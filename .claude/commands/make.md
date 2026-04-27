Gera um componente Hyperf novo baseado no argumento passado.

Formatos aceitos:
- `/make process NomeDoProcess` → cria `app/Process/NomeDoProcess.php`
- `/make controller NomeDoController` → cria `app/Controller/NomeDoController.php`
- `/make service NomeDoService` → cria `app/Service/NomeDoService.php`
- `/make model NomeDoModel` → cria `app/Model/NomeDoModel.php`

**Regras para cada tipo:**

### Process
- Extende `AbstractProcess` de `Hyperf\Process\AbstractProcess`
- Método `handle(): void` com loop infinito usando `Coroutine::sleep()`
- Registrar em `config/autoload/processes.php`
- Incluir injeção de dependências via construtor com `ContainerInterface`

### Controller
- Extende `AbstractController` de `App\Controller\AbstractController`
- Usar atributos `#[Controller]` e `#[RequestMapping]`
- Retornar `$this->response->json([...])`
- Injetar serviços via `#[Inject]`

### Service
- Classe simples com `#[Injectable]`
- Injetar `Redis`, `Logger` etc. via construtor com `#[Inject]`
- Métodos públicos com tipos explícitos

### Model
- Extende `App\Model\Model`
- Definir `$table`, `$fillable`, `$hidden`
- Usar `$casts` para tipos

**Após criar**, informe quais arquivos foram criados e o que ainda precisa ser feito manualmente (ex: registrar o Process, adicionar rota).
