# notification-email-server

Servidor de push notifications para clientes de email móveis. Monitora caixas de entrada via **IMAP IDLE** e envia notificações para dispositivos móveis via **Firebase FCM v1 API**.

```
App Flutter registra dispositivo
        ↓
POST /register  (IMAP credentials + FCM token)
        ↓
ImapIdleProcess abre conexão IMAP e aguarda
        ↓
Novo email chega → * N EXISTS
        ↓
FcmService envia push → dispositivo recebe notificação
```

## Stack

| Camada | Tecnologia |
|---|---|
| Runtime | PHP 8.4 + Swoole 5.x |
| Framework | Hyperf 3.1 |
| Banco de dados | MySQL 8.0 |
| Cache | Redis 7 |
| Push | Firebase FCM v1 API |
| Concorrência | Swoole Coroutines (uma por conta IMAP) |

## Requisitos

- Docker + Docker Compose

## Setup

### 1. Variáveis de ambiente

```bash
cp .env.example .env
```

Edite o `.env` e configure:

| Variável | Descrição |
|---|---|
| `APP_KEY` | Chave AES-256 para criptografar senhas IMAP. Gere com: `php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"` |
| `FCM_PROJECT_ID` | ID do projeto Firebase |
| `FCM_CREDENTIALS_PATH` | Caminho para o JSON de service account do Firebase |

### 2. Credenciais Firebase

Coloque o arquivo de service account do Firebase em:

```
storage/firebase/orbe_cliente_oauth.json
```

> O arquivo está no `.gitignore` — nunca suba credenciais reais para o repositório.

### 3. Subir os containers

```bash
docker compose up --build
```

MySQL e Redis sobem primeiro (healthcheck). O Hyperf inicia automaticamente depois que ambos estiverem prontos.

### 4. Rodar as migrations

```bash
docker exec -it hyperf php bin/hyperf.php migrate
```

## API

### `POST /register`

Registra um dispositivo para receber notificações.

```json
{
  "fcm_token":    "token-do-firebase-sdk",
  "email_address":"user@example.com",
  "display_name": "João Silva",
  "imap_host":    "imap.example.com",
  "imap_port":    993,
  "imap_ssl":     true,
  "imap_user":    "user@example.com",
  "imap_password":"senha"
}
```

Resposta `201`:
```json
{ "id": 1 }
```

Chamar novamente com o mesmo `fcm_token` atualiza o registro (upsert).

---

### `DELETE /unregister`

Desativa notificações para um dispositivo.

```json
{ "fcm_token": "token-do-firebase-sdk" }
```

Resposta `204` (sem corpo).

---

### `GET /health`

```json
{ "status": "ok", "ts": 1714000000 }
```

## Comandos úteis

```bash
# Ver logs em tempo real
docker compose logs -f hyperf

# Acessar shell no container
docker exec -it hyperf sh

# Reiniciar após mudanças no código
docker compose restart hyperf

# Status das migrations
docker exec -it hyperf php bin/hyperf.php migrate:status

# Parar tudo
docker compose down

# Parar e apagar banco (reset completo)
docker compose down -v
```

## Arquitetura

```
app/
├── Controller/
│   └── NotificationController.php  # POST /register, DELETE /unregister
├── Model/
│   └── RegisteredDevice.php        # Eloquent model
├── Process/
│   └── ImapIdleProcess.php         # Swoole process — uma coroutine por device
├── Repository/
│   └── DeviceRepository.php        # Cache Redis (TTL 5 min) + fallback MySQL
└── Service/
    ├── FcmService.php               # Firebase FCM v1 API + cache token Redis
    └── ImapConnectionService.php    # IMAP raw TCP via Swoole\Coroutine\Socket
```

### ImapIdleProcess

Processo Swoole permanente que gerencia todas as conexões IMAP. A cada 60 segundos verifica se há novos devices registrados e abre uma coroutine para cada um. Cada coroutine mantém uma conexão IMAP IDLE aberta e reconecta automaticamente com backoff (5s → 10s → 20s) em caso de falha.

### Segurança

- Senhas IMAP armazenadas criptografadas com AES-256-CBC usando `APP_KEY`
- Token OAuth2 do Firebase cacheado no Redis com TTL de 55 minutos
- Credenciais Firebase fora do controle de versão (`storage/firebase/*.json` no `.gitignore`)
