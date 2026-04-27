Executa operações de desenvolvimento no ambiente Docker do projeto.

Ações disponíveis (passar como argumento):
- `/dev up` → sobe o ambiente (`docker compose up -d`) e mostra a URL
- `/dev down` → derruba o ambiente
- `/dev restart` → reinicia o container Hyperf (necessário após mudanças de código)
- `/dev logs` → exibe os últimos logs do container
- `/dev test` → roda a suite de testes dentro do container
- `/dev shell` → mostra o comando para entrar no container
- `/dev analyse` → roda PHPStan dentro do container

Se nenhum argumento for passado, mostra o status atual do ambiente (containers rodando, porta, etc.).

**Contexto importante:**
- Container name: `hyperf-skeleton`
- Porta: `9501`
- Código montado em `/opt/www` dentro do container
- Após editar arquivos PHP, sempre reiniciar o container (Swoole não tem hot-reload nativo)
