# notification-email-server

Servidor de notificações push para o app Flutter `email_client_flutter`. Mantém conexões IMAP IDLE por usuário e envia notificações via FCM quando novos emails chegam.

## Stack

- **PHP 8.1+** com **Hyperf 3.1** (framework coroutine sobre Swoole)
- **Swoole** — processo long-running, coroutines para N conexões IMAP simultâneas
- **Redis** — armazena registros de dispositivos (email ↔ FCM token)
- **MySQL** — persistência de logs e histórico (opcional para POC)

## Rodar o projeto

```bash
# Subir tudo (Hyperf + Redis + MySQL)
docker compose up

# Servidor sobe em http://localhost:9501
# Recarregar após mudanças no código:
docker compose restart hyperf-skeleton
```

## Estrutura planejada

```
app/
├── Controller/
│   └── NotificationController.php   # POST /register, DELETE /unregister
├── Process/
│   └── ImapIdleProcess.php          # processo permanente — coroutine por usuário
├── Service/
│   ├── ImapIdleService.php          # socket TCP puro via Swoole, fala protocolo IMAP
│   └── FcmService.php               # POST https://fcm.googleapis.com/v1/messages:send
├── Model/
│   └── RegisteredDevice.php         # email, fcm_token, imap_host, imap_port, imap_ssl
└── Exception/
    └── ImapException.php
config/
├── autoload/
│   ├── processes.php                # registra ImapIdleProcess
│   ├── redis.php
│   └── databases.php
└── routes.php                       # POST /register, DELETE /unregister
```

## API (a implementar)

```
POST   /register
Body:  { "email": "...", "password": "...", "fcm_token": "...",
         "imap_host": "imap.kinghost.com.br", "imap_port": 993, "imap_ssl": true }
→ 201 Created

DELETE /unregister
Body:  { "fcm_token": "..." }
→ 204 No Content
```

## Fluxo de notificação

```
Flutter app → POST /register → Redis salva device
ImapIdleProcess (boot) → lê Redis → abre Coroutine por device
  └─ Swoole\Coroutine\Socket → TCP → IMAP IDLE
     └─ "* N EXISTS" detectado → FcmService::send(fcm_token)
        └─ POST FCM API → push no celular
```

## Conceitos Hyperf importantes

- **Process**: classe que roda em paralelo ao servidor HTTP, inicia no boot. Registrar em `config/autoload/processes.php`.
- **Coroutine**: `Coroutine::create(fn() => ...)` — leve, não bloqueia o processo.
- **Swoole\Coroutine\Socket**: socket TCP coroutine-native. Usar para IMAP em vez de libs bloqueantes.
- **Container/DI**: injeção via construtor com `#[Inject]` ou `make()`.
- **Redis**: injetar `Hyperf\Redis\Redis` via DI.

## Comandos úteis

```bash
# Entrar no container
docker exec -it hyperf-skeleton bash

# Dentro do container — rodar servidor manualmente
php bin/hyperf.php start

# Testes
composer test

# Code style fix
composer cs-fix

# Static analysis
composer analyse
```

## Variáveis de ambiente (.env)

```
FCM_SERVER_KEY=           # Firebase Server Key (Settings > Cloud Messaging)
FCM_API_URL=https://fcm.googleapis.com/v1/projects/{project_id}/messages:send
DB_HOST=mysql
DB_DATABASE=notifications
REDIS_HOST=redis
```

## Relação com o app Flutter

O app em `../email_client_flutter` chama `POST /register` ao abrir e `DELETE /unregister` ao fechar/deslogar. O servidor mantém a conexão IMAP IDLE enquanto o dispositivo estiver registrado.
