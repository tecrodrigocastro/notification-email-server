<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\RegisteredDevice;
use Hyperf\Database\Model\Collection;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;

class DeviceRepository
{
    private const CACHE_KEY = 'devices:active';
    private const CACHE_TTL = 5 * 60; // 5 minutos

    private LoggerInterface $logger;

    public function __construct(
        private readonly Redis $redis,
        LoggerFactory $loggerFactory,
    ) {
        $this->logger = $loggerFactory->get('device-repository');
    }

    public function getActiveDevices(): Collection
    {
        $cached = $this->redis->get(self::CACHE_KEY);
        if ($cached) {
            $rows = json_decode((string) $cached, true);
            $devices = new Collection();
            foreach ($rows as $row) {
                $device = new RegisteredDevice();
                $device->forceFill($row);
                $device->exists = true;
                $devices->push($device);
            }
            return $devices;
        }

        /** @var Collection $devices */
        $devices = RegisteredDevice::active()->get();

        $serializable = $devices->map(fn (RegisteredDevice $d) => $d->getAttributes())->toArray();
        $this->redis->setex(self::CACHE_KEY, self::CACHE_TTL, json_encode($serializable));

        $this->logger->debug('Active devices loaded from MySQL', ['count' => $devices->count()]);

        return $devices;
    }

    public function invalidateCache(): void
    {
        $this->redis->del(self::CACHE_KEY);
        $this->logger->debug('Device cache invalidated');
    }
}
