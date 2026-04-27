# Backlog — notification-email-server

> Servidor de notificações IMAP IDLE → FCM push para o app Flutter KingHost Email.
> Stack: PHP 8.4, Hyperf 3.1, Swoole 5.x, Redis, MySQL, Firebase FCM v1 API.

---

## EPIC-1 — Infraestrutura e Ambiente

### TASK-1 · Adicionar Redis e MySQL ao docker-compose.yml
**Pontos:** 2 · **Prioridade:** Crítica · **Depende de:** —

O `docker-compose.yml` atual só tem o serviço Hyperf. Sem Redis e MySQL como serviços, o servidor não inicializa.

**Arquivo:** `docker-compose.yml`

Adicionar serviços:
- `redis:7-alpine` na porta 6379
- `mysql:8.0` na porta 3306 com variáveis de ambiente e volume persistente
- `depends_on` no hyperf-skeleton
- Network compartilhada entre todos os serviços

**Critérios de aceite:**
- [ ] `docker compose up` sobe os 3 serviços sem erro
- [ ] Hyperf conecta no Redis e MySQL pelos hostnames `redis` e `mysql`

---

### TASK-2 · Atualizar .env com hosts corretos
**Pontos:** 1 · **Prioridade:** Crítica · **Depende de:** TASK-1

O `.env` atual tem `DB_HOST=localhost` e `REDIS_HOST=localhost`, mas dentro do Docker os hosts são os nomes dos serviços.

**Arquivos:** `.env`, `.env.example`

Atualizar:
- `DB_HOST=mysql`, `DB_DATABASE=notifications`, `DB_USERNAME=hyperf`, `DB_PASSWORD=secret`
- `REDIS_HOST=redis`

**Critérios de aceite:**
- [ ] `docker compose up` sem erro de conexão com banco/redis

---

### TASK-3 · Instalar `google/apiclient` via Composer
**Pontos:** 1 · **Prioridade:** Crítica · **Depende de:** —

Necessário para gerar Bearer token OAuth2 do Firebase a partir do service account JSON.

```bash
composer require google/apiclient:^2.15
```

Adicionar ao `.env` e `.env.example`:
```
FCM_CREDENTIALS_PATH=/opt/www/storage/firebase/orbe_cliente_oauth.json
FCM_PROJECT_ID=orbecliente
```

**Critérios de aceite:**
- [ ] `vendor/google/apiclient` existe após `composer install`
- [ ] `Google\Client` importável em classes PHP

---

### TASK-4 · Mover orbe_cliente_oauth.json para storage e ignorar no git
**Pontos:** 1 · **Prioridade:** Alta · **Depende de:** —

O arquivo contém chave privada real e está rastreado pelo git — risco de segurança.

**Ações:**
- Criar `storage/firebase/`
- Mover `orbe_cliente_oauth.json` para `storage/firebase/`
- Adicionar `storage/firebase/*.json` ao `.gitignore`
- Criar `storage/firebase/orbe_cliente_oauth.json.example` com estrutura vazia

**Critérios de aceite:**
- [ ] `git status` não mostra o arquivo com chave privada
- [ ] `storage/firebase/` está no `.gitignore`

---

## EPIC-2 — Banco de Dados

### TASK-5 · Criar migration `registered_devices`
**Pontos:** 2 · **Prioridade:** Crítica · **Depende de:** TASK-1, TASK-2

**Arquivo:** `database/migrations/2025_xx_xx_create_registered_devices_table.php`

Colunas:

| Coluna | Tipo | Notas |
|---|---|---|
| id | bigint PK auto | |
| fcm_token | varchar(512) unique | token do dispositivo Flutter |
| imap_host | varchar(255) | ex: imap.kinghost.com.br |
| imap_port | smallint | padrão 993 |
| imap_ssl | tinyint(1) | padrão 1 |
| imap_user | varchar(255) | email/username |
| imap_password | text | criptografado AES-256 |
| email_address | varchar(255) | |
| display_name | varchar(255) nullable | |
| last_seen_uid | int unsigned nullable | evita notif. duplicada |
| is_active | tinyint(1) | padrão 1 |
| created_at / updated_at | timestamps | |

**Critérios de aceite:**
- [ ] `php bin/hyperf.php migrate` roda sem erro
- [ ] Tabela criada com todas as colunas

---

### TASK-6 · Criar Model `RegisteredDevice`
**Pontos:** 1 · **Prioridade:** Alta · **Depende de:** TASK-5

**Arquivo:** `app/Model/RegisteredDevice.php`

- Extende `App\Model\Model`
- `$fillable` com todas as colunas
- `$hidden = ['imap_password']`
- Cast `is_active` para bool
- Scope local `active()` → `where('is_active', 1)`

**Critérios de aceite:**
- [ ] `RegisteredDevice::active()->get()` retorna collection sem erro

---

### TASK-7 · Criar migration `notification_logs`
**Pontos:** 1 · **Prioridade:** Média · **Depende de:** TASK-5

Log de cada push enviado para auditoria e debug.

**Arquivo:** `database/migrations/2025_xx_xx_create_notification_logs_table.php`

Colunas: `id`, `device_id` (FK → registered_devices cascade delete), `sent_at`, `status` enum(sent, failed), `error_message` text nullable, `email_subject` varchar(255) nullable.

**Critérios de aceite:**
- [ ] Migration roda sem erro
- [ ] FK com cascade delete funciona

---

## EPIC-3 — API REST

### TASK-8 · Criar `NotificationController` — POST /register
**Pontos:** 3 · **Prioridade:** Crítica · **Depende de:** TASK-6

**Arquivo:** `app/Controller/NotificationController.php`

Body esperado:
```json
{
  "fcm_token": "...",
  "email_address": "user@kinghost.com.br",
  "imap_host": "imap.kinghost.com.br",
  "imap_port": 993,
  "imap_ssl": true,
  "imap_user": "user@kinghost.com.br",
  "imap_password": "...",
  "display_name": "João"
}
```

Lógica:
1. Validar campos obrigatórios
2. Criptografar `imap_password` com `openssl_encrypt` + `APP_KEY` do `.env`
3. `RegisteredDevice::updateOrCreate(['fcm_token' => ...], [...])`
4. Invalidar cache Redis (`devices:active`)
5. Retornar `201 {"id": ...}`

**Critérios de aceite:**
- [ ] POST válido → 201 + registro no banco
- [ ] POST com mesmo `fcm_token` → atualiza, não duplica
- [ ] POST sem campos obrigatórios → 422

---

### TASK-9 · Adicionar DELETE /unregister ao NotificationController
**Pontos:** 2 · **Prioridade:** Alta · **Depende de:** TASK-8

Body: `{ "fcm_token": "..." }`

Lógica: buscar device → setar `is_active = 0` → invalidar cache Redis → `204 No Content`.

**Critérios de aceite:**
- [ ] DELETE válido → 204 + `is_active = 0` no banco
- [ ] DELETE com token inexistente → 404

---

### TASK-10 · Registrar rotas em config/routes.php
**Pontos:** 1 · **Prioridade:** Crítica · **Depende de:** TASK-8, TASK-9

**Arquivo:** `config/routes.php`

```php
Router::post('/register', [NotificationController::class, 'register']);
Router::delete('/unregister', [NotificationController::class, 'unregister']);
Router::get('/health', fn() => ['status' => 'ok', 'ts' => time()]);
```

**Critérios de aceite:**
- [ ] `curl -X POST localhost:9501/register` → 422
- [ ] `curl localhost:9501/health` → 200

---

## EPIC-4 — Serviço FCM Push

### TASK-11 · Criar `FcmService`
**Pontos:** 3 · **Prioridade:** Crítica · **Depende de:** TASK-3, TASK-4

Port do `PushNotificationService` Laravel de referência para Hyperf com Guzzle coroutine-safe.

**Arquivo:** `app/Service/FcmService.php`

```php
class FcmService {
    public function send(string $fcmToken, string $title, string $body): bool
    private function getAccessToken(): string
}
```

- `Google\Client::setAuthConfig(env('FCM_CREDENTIALS_PATH'))`
- Scope: `https://www.googleapis.com/auth/firebase.messaging`
- URL: `https://fcm.googleapis.com/v1/projects/{FCM_PROJECT_ID}/messages:send`
- Usar `Hyperf\Guzzle\ClientFactory` (não `Http::` do Laravel)
- Cache do access token no Redis com TTL 55 min (token dura 60 min)

Payload FCM:
```json
{
  "message": {
    "token": "{fcmToken}",
    "notification": { "title": "...", "body": "..." }
  }
}
```

**Critérios de aceite:**
- [ ] Push chega no dispositivo Flutter
- [ ] Access token cacheado no Redis (verificar com `redis-cli get fcm:access_token`)
- [ ] Erro 401 FCM lança exceção com mensagem clara

---

## EPIC-5 — IMAP IDLE Process

### TASK-12 · Criar `ImapConnectionService`
**Pontos:** 5 · **Prioridade:** Crítica · **Depende de:** —

Gerencia uma única conexão IMAP IDLE via `Swoole\Coroutine\Socket` (TCP puro, coroutine-native).

**Arquivo:** `app/Service/ImapConnectionService.php`

```php
class ImapConnectionService {
    public function connect(string $host, int $port, bool $ssl): void
    public function login(string $user, string $password): void
    public function selectInbox(): void
    public function idle(callable $onNewEmail): void  // bloqueia coroutine, chama callback em EXISTS
    public function disconnect(): void
}
```

Protocolo IMAP via socket:
```
→ tag LOGIN user pass\r\n   ← tag OK
→ tag SELECT INBOX\r\n      ← tag OK [uidvalidity ...]
→ tag IDLE\r\n              ← + idling
← * N EXISTS                → callback onNewEmail se N > last_seen
→ DONE\r\n
```

SSL: `Swoole\Coroutine\Socket` com `setOption(SWOOLE_KEEP_ALIVE)` + TLS via `ssl_connect()`.

**Critérios de aceite:**
- [ ] Conecta em `imap.kinghost.com.br:993` com SSL sem erro
- [ ] `* N EXISTS` com N > `last_seen_uid` dispara callback
- [ ] Desconexão inesperada lança `App\Exception\ImapException`

---

### TASK-13 · Criar `ImapIdleProcess`
**Pontos:** 5 · **Prioridade:** Crítica · **Depende de:** TASK-11, TASK-12, TASK-6

Process Hyperf permanente — uma coroutine por device registrado ativo.

**Arquivo:** `app/Process/ImapIdleProcess.php`

```php
class ImapIdleProcess extends AbstractProcess {
    public string $name = 'imap-idle';

    public function handle(): void {
        // Boot: cria coroutines para todos os devices ativos
        // Loop a cada 60s: verifica novos devices, abre coroutines para eles
    }

    private function watchDevice(RegisteredDevice $device): void {
        // Reconnect loop: máx 3 tentativas, backoff 5s/10s/20s
        // ImapConnectionService->idle(fn() => FcmService->send(...))
        // Atualiza last_seen_uid no banco após notificação
    }
}
```

**Critérios de aceite:**
- [ ] Process inicia com `php bin/hyperf.php start`
- [ ] Um device registrado → uma coroutine IMAP IDLE ativa
- [ ] Novo device (register API) → coroutine criada em até 60s
- [ ] Device desativado (unregister API) → coroutine encerrada
- [ ] Falha IMAP → 3 tentativas com backoff → log de erro

---

### TASK-14 · Registrar ImapIdleProcess em config/autoload/processes.php
**Pontos:** 1 · **Prioridade:** Crítica · **Depende de:** TASK-13

**Arquivo:** `config/autoload/processes.php`

```php
return [App\Process\ImapIdleProcess::class];
```

**Critérios de aceite:**
- [ ] Logs mostram `[imap-idle] Process started` ao iniciar o servidor

---

## EPIC-6 — Cache Redis

### TASK-15 · Criar `DeviceRepository` com cache Redis
**Pontos:** 2 · **Prioridade:** Alta · **Depende de:** TASK-6

**Arquivo:** `app/Repository/DeviceRepository.php`

```php
class DeviceRepository {
    public function getActiveDevices(): Collection  // Redis → MySQL fallback, TTL 5 min
    public function invalidateCache(): void
}
```

Chave Redis: `devices:active`. Serialização: JSON.

**Critérios de aceite:**
- [ ] Segunda chamada não faz query SQL (verificar logs do DbQueryExecutedListener)
- [ ] Após `invalidateCache()`, próxima chamada vai ao MySQL

---

## Ordem de execução sugerida

```
Sprint 1 (ambiente + banco):
  TASK-1 → TASK-2 → TASK-3 → TASK-4 → TASK-5 → TASK-6 → TASK-7

Sprint 2 (API + FCM):
  TASK-8 → TASK-9 → TASK-10 → TASK-11

Sprint 3 (IMAP IDLE + otimizações):
  TASK-12 → TASK-13 → TASK-14 → TASK-15
```

**MVP funcional:** TASK-1 ao TASK-14 (sem cache Redis).
**Total estimado:** 30 pontos de história.
