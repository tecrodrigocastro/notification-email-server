<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\RegisteredDevice;
use App\Repository\DeviceRepository;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\PostMapping;

#[Controller(prefix: '/')]
class NotificationController extends AbstractController
{
    #[Inject]
    protected DeviceRepository $deviceRepository;

    #[PostMapping(path: 'register')]
    public function register(): \Psr\Http\Message\ResponseInterface
    {
        $body = $this->request->all();

        $required = ['fcm_token', 'email_address', 'imap_host', 'imap_port', 'imap_user', 'imap_password'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                return $this->response->json([
                    'message' => "O campo '{$field}' é obrigatório.",
                ])->withStatus(422);
            }
        }

        $encrypted = $this->encryptPassword($body['imap_password']);

        $device = RegisteredDevice::updateOrCreate(
            ['fcm_token' => $body['fcm_token']],
            [
                'email_address' => $body['email_address'],
                'display_name'  => $body['display_name'] ?? null,
                'imap_host'     => $body['imap_host'],
                'imap_port'     => (int) $body['imap_port'],
                'imap_ssl'      => filter_var($body['imap_ssl'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'imap_user'     => $body['imap_user'],
                'imap_password' => $encrypted,
                'is_active'     => 1,
            ]
        );

        $this->deviceRepository->invalidateCache();

        return $this->response->json(['id' => $device->id])->withStatus(201);
    }

    #[DeleteMapping(path: 'unregister')]
    public function unregister(): \Psr\Http\Message\ResponseInterface
    {
        $fcmToken = $this->request->input('fcm_token');

        if (empty($fcmToken)) {
            return $this->response->json(['message' => "O campo 'fcm_token' é obrigatório."])->withStatus(422);
        }

        $device = RegisteredDevice::where('fcm_token', $fcmToken)->first();

        if (! $device) {
            return $this->response->json(['message' => 'Dispositivo não encontrado.'])->withStatus(404);
        }

        $device->is_active = false;
        $device->save();

        $this->deviceRepository->invalidateCache();

        return $this->response->withStatus(204);
    }

    private function encryptPassword(string $password): string
    {
        $key = base64_decode(str_replace('base64:', '', (string) env('APP_KEY', '')));
        if (strlen($key) < 16) {
            // Fallback: use key as raw string padded
            $key = str_pad((string) env('APP_KEY', 'changeme'), 32, '0');
        }
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($password, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . '::' . $encrypted);
    }

    public static function decryptPassword(string $encrypted): string
    {
        $key = base64_decode(str_replace('base64:', '', (string) env('APP_KEY', '')));
        if (strlen($key) < 16) {
            $key = str_pad((string) env('APP_KEY', 'changeme'), 32, '0');
        }
        $decoded = base64_decode($encrypted);
        [$iv, $data] = explode('::', $decoded, 2);
        return (string) openssl_decrypt($data, 'AES-256-CBC', $key, 0, $iv);
    }
}
