<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ImapException;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine\Socket;

class ImapConnectionService
{
    private ?Socket $socket = null;
    private int $tagCounter = 0;
    private int $lastSeenCount = 0;

    private LoggerInterface $logger;

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->logger = $loggerFactory->get('imap');
    }

    public function connect(string $host, int $port, bool $ssl): void
    {
        $socketType = $ssl ? SWOOLE_SOCK_TCP | SWOOLE_SSL : SWOOLE_SOCK_TCP;
        $this->socket = new Socket(AF_INET, SOCK_STREAM, $socketType);
        $this->socket->setOption(SOL_SOCKET, SO_KEEPALIVE, 1);

        if ($ssl) {
            $this->socket->setProtocol([
                'open_ssl'          => true,
                'ssl_verify_peer'   => false,
            ]);
        }

        if (! $this->socket->connect($host, $port, 10)) {
            throw new ImapException("Falha ao conectar em {$host}:{$port} — errCode={$this->socket->errCode}");
        }

        // Consumir greeting (* OK ...)
        $greeting = $this->readLine();
        if (! str_starts_with($greeting, '* OK')) {
            throw new ImapException("Greeting inesperado: {$greeting}");
        }

        $this->logger->info("IMAP conectado", ['host' => $host, 'port' => $port, 'ssl' => $ssl]);
    }

    public function login(string $user, string $password): void
    {
        $tag = $this->nextTag();
        $this->send("{$tag} LOGIN {$user} {$password}");
        $response = $this->readUntilTag($tag);
        if (! $this->isOk($response, $tag)) {
            throw new ImapException("LOGIN falhou: {$response}");
        }
    }

    public function selectInbox(): void
    {
        $tag = $this->nextTag();
        $this->send("{$tag} SELECT INBOX");
        $lines = $this->readUntilTagLines($tag);

        foreach ($lines as $line) {
            // * N EXISTS indica quantos mensagens há na caixa atualmente
            if (preg_match('/^\* (\d+) EXISTS/', $line, $m)) {
                $this->lastSeenCount = (int) $m[1];
            }
        }

        $last = end($lines);
        if (! $this->isOk($last, $tag)) {
            throw new ImapException("SELECT INBOX falhou: {$last}");
        }
    }

    /**
     * Entra em modo IDLE e bloqueia a coroutine até receber * N EXISTS com N > lastSeenCount.
     * Chama $onNewEmail($newCount) e retorna. O chamador é responsável por chamar idle() novamente.
     *
     * @param callable(int): void $onNewEmail
     */
    public function idle(callable $onNewEmail): void
    {
        $tag = $this->nextTag();
        $this->send("{$tag} IDLE");

        // Aguardar confirmação "+ idling"
        $continuation = $this->readLine();
        if (! str_starts_with($continuation, '+')) {
            throw new ImapException("IDLE não confirmado: {$continuation}");
        }

        // Loop: ler respostas unilaterais até detectar novo email ou timeout (28 min)
        $deadline = time() + 28 * 60;
        while (time() < $deadline) {
            $line = $this->readLine(timeout: 30);

            if ($line === null) {
                // Timeout de leitura — iterar para verificar deadline
                continue;
            }

            if (preg_match('/^\* (\d+) EXISTS/', $line, $m)) {
                $newCount = (int) $m[1];
                if ($newCount > $this->lastSeenCount) {
                    $this->lastSeenCount = $newCount;
                    $this->send('DONE');
                    $this->readUntilTag($tag); // consumir OK do IDLE
                    $onNewEmail($newCount);
                    return;
                }
            }

            // Servidor encerrou IDLE externamente (BYE ou tag OK)
            if (str_starts_with($line, "{$tag} OK") || str_starts_with($line, '* BYE')) {
                return;
            }
        }

        // Deadline atingido — sair do IDLE graciosamente para re-entrar
        $this->send('DONE');
        $this->readUntilTag($tag);
    }

    public function getLastSeenCount(): int
    {
        return $this->lastSeenCount;
    }

    public function disconnect(): void
    {
        if ($this->socket === null) {
            return;
        }
        try {
            $tag = $this->nextTag();
            $this->send("{$tag} LOGOUT");
        } catch (\Throwable) {
            // ignorar erros no logout
        } finally {
            $this->socket->close();
            $this->socket = null;
        }
    }

    private function send(string $command): void
    {
        if ($this->socket === null) {
            throw new ImapException('Socket não conectado.');
        }
        $result = $this->socket->send($command . "\r\n");
        if ($result === false) {
            throw new ImapException("Falha ao enviar comando — errCode={$this->socket->errCode}");
        }
    }

    private function readLine(int $timeout = 60): ?string
    {
        if ($this->socket === null) {
            throw new ImapException('Socket não conectado.');
        }

        $buffer = '';
        $this->socket->recvTimeout = $timeout;

        while (true) {
            $chunk = $this->socket->recv(1);
            if ($chunk === false || $chunk === '') {
                if ($this->socket->errCode === SOCKET_ETIMEDOUT) {
                    return null;
                }
                throw new ImapException("Conexão encerrada pelo servidor — errCode={$this->socket->errCode}");
            }
            $buffer .= $chunk;
            if (str_ends_with($buffer, "\r\n")) {
                return rtrim($buffer, "\r\n");
            }
        }
    }

    private function readUntilTag(string $tag): string
    {
        $lines = $this->readUntilTagLines($tag);
        return end($lines) ?: '';
    }

    private function readUntilTagLines(string $tag): array
    {
        $lines = [];
        while (true) {
            $line = $this->readLine();
            if ($line === null) {
                throw new ImapException('Timeout aguardando resposta do servidor IMAP.');
            }
            $lines[] = $line;
            if (str_starts_with($line, "{$tag} ")) {
                break;
            }
            if (str_starts_with($line, '* BYE')) {
                throw new ImapException("Servidor IMAP enviou BYE: {$line}");
            }
        }
        return $lines;
    }

    private function isOk(string $line, string $tag): bool
    {
        return str_starts_with($line, "{$tag} OK");
    }

    private function nextTag(): string
    {
        return 'A' . str_pad((string) ++$this->tagCounter, 4, '0', STR_PAD_LEFT);
    }
}
