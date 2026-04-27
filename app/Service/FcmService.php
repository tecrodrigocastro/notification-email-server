<?php

declare(strict_types=1);

namespace App\Service;

use Google\Client;
use Hyperf\Guzzle\ClientFactory;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;

class FcmService
{
    private const TOKEN_CACHE_KEY = 'fcm:access_token';
    private const TOKEN_TTL = 55 * 60; // 55 minutos (token dura 60 min)

    private LoggerInterface $logger;

    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly Redis $redis,
        LoggerFactory $loggerFactory,
    ) {
        $this->logger = $loggerFactory->get('fcm');
    }

    public function send(string $fcmToken, string $title, string $body): bool
    {
        $projectId = (string) env('FCM_PROJECT_ID', 'orbecliente');
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $payload = [
            'message' => [
                'token'        => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body'  => $body,
                ],
            ],
        ];

        try {
            $client = $this->clientFactory->create();
            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('FCM push enviado', ['token' => substr($fcmToken, 0, 20) . '...']);
                return true;
            }

            $this->logger->error('FCM retornou erro', [
                'status' => $statusCode,
                'body'   => (string) $response->getBody(),
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Erro ao enviar FCM push', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function getAccessToken(): string
    {
        $cached = $this->redis->get(self::TOKEN_CACHE_KEY);
        if ($cached) {
            return (string) $cached;
        }

        $client = new Client();
        $client->setAuthConfig((string) env('FCM_CREDENTIALS_PATH'));
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
        $client->useApplicationDefaultCredentials();

        $tokenData = $client->fetchAccessTokenWithAssertion();

        if (empty($tokenData['access_token'])) {
            throw new \RuntimeException('Não foi possível obter o access token do Firebase.');
        }

        $token = $tokenData['access_token'];
        $this->redis->setex(self::TOKEN_CACHE_KEY, self::TOKEN_TTL, $token);

        return $token;
    }
}
