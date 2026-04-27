<?php

declare(strict_types=1);

namespace App\Process;

use App\Controller\NotificationController;
use App\Exception\ImapException;
use App\Model\RegisteredDevice;
use App\Repository\DeviceRepository;
use App\Service\FcmService;
use App\Service\ImapConnectionService;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Process\AbstractProcess;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Swoole\Timer;

class ImapIdleProcess extends AbstractProcess
{
    public string $name = 'imap-idle';

    private LoggerInterface $logger;

    /** @var array<int, bool> device IDs being watched */
    private array $watching = [];

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->logger = $container->get(LoggerFactory::class)->get('imap-idle');
    }

    public function handle(): void
    {
        $this->logger->info('ImapIdleProcess started');

        // Bootstrap: start a coroutine for each active device
        $this->syncDevices();

        // Every 60s check for newly registered devices
        Timer::tick(60_000, function () {
            $this->syncDevices();
        });

        // Keep the process alive
        while (true) {
            Coroutine::sleep(3600);
        }
    }

    private function syncDevices(): void
    {
        /** @var DeviceRepository $repo */
        $repo = $this->container->get(DeviceRepository::class);
        $devices = $repo->getActiveDevices();

        foreach ($devices as $device) {
            if (isset($this->watching[$device->id])) {
                continue;
            }
            $this->watching[$device->id] = true;
            Coroutine::create(fn () => $this->watchDevice($device));
        }

        $this->logger->info('Watching devices', ['count' => count($this->watching)]);
    }

    private function watchDevice(RegisteredDevice $device): void
    {
        $backoffs = [5, 10, 20];
        $attempt  = 0;

        while (true) {
            /** @var ImapConnectionService $imap */
            $imap = $this->container->get(ImapConnectionService::class);

            try {
                $password = NotificationController::decryptPassword($device->imap_password);

                $imap->connect($device->imap_host, $device->imap_port, $device->imap_ssl);
                $imap->login($device->imap_user, $password);
                $imap->selectInbox();

                // Sync last_seen_uid with the current server count so we don't
                // fire a notification for emails that already existed at connect time
                if ($device->last_seen_uid === null) {
                    $device->last_seen_uid = $imap->getLastSeenCount();
                    $device->save();
                }

                $attempt = 0; // reset after successful connect

                // Re-enter IDLE in a loop; idle() returns after each new-email event
                while (true) {
                    $imap->idle(function (int $newCount) use ($device) {
                        $this->sendPush($device, $newCount);
                    });
                }
            } catch (ImapException $e) {
                $this->logger->warning('IMAP error', [
                    'device_id' => $device->id,
                    'email'     => $device->email_address,
                    'error'     => $e->getMessage(),
                    'attempt'   => $attempt + 1,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Unexpected error in watchDevice', [
                    'device_id' => $device->id,
                    'error'     => $e->getMessage(),
                ]);
            } finally {
                try {
                    $imap->disconnect();
                } catch (\Throwable) {
                }
            }

            if ($attempt >= count($backoffs)) {
                $this->logger->error('Max reconnect attempts reached, giving up', ['device_id' => $device->id]);
                unset($this->watching[$device->id]);
                return;
            }

            $sleep = $backoffs[$attempt++];
            $this->logger->info("Reconnecting in {$sleep}s", ['device_id' => $device->id]);
            Coroutine::sleep($sleep);

            // Reload device — it might have been deactivated while we slept
            $fresh = RegisteredDevice::find($device->id);
            if (! $fresh || ! $fresh->is_active) {
                $this->logger->info('Device deactivated, stopping coroutine', ['device_id' => $device->id]);
                unset($this->watching[$device->id]);
                return;
            }
            $device = $fresh;
        }
    }

    private function sendPush(RegisteredDevice $device, int $newCount): void
    {
        /** @var FcmService $fcm */
        $fcm = $this->container->get(FcmService::class);

        $sent = $fcm->send(
            $device->fcm_token,
            'Novo email recebido',
            "Você recebeu um novo email em {$device->email_address}"
        );

        if ($sent) {
            $device->last_seen_uid = $newCount;
            $device->save();
        }
    }
}
