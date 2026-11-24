# Laravel Resilience Kit

O **Laravel Resilience Kit** é um pacote moderno, modular e altamente extensível para o ecossistema Laravel (compátivel com Laravel 11.x / 12.x e PHP 8.3+), projetado para prover mecanismos avançados de tolerância a falhas e resiliência em arquiteturas distribuídas.

Nesta primeira versão, o pacote entrega uma implementação robusta do padrão **Circuit Breaker** integrando armazenamento atômico distribuído (Redis/Cache), suporte nativo ao **Laravel Sail** para ambiente Docker local e um **Painel de Monitoramento (Dashboard)** embutido com suporte a reset manual de circuitos.

---

## 💡 Funcionalidades Principais

- ⚡ **Circuit Breaker de Alta Performance:** Suporte a 3 estados clássicos (`Closed`, `Open`, `Half-Open`) com contadores atômicos em Redis/Cache.
- 🐳 **Ambiente Docker Nativo (Laravel Sail):** Configuração pronta com PHP 8.3/8.4, Redis e ferramentas de testes para desenvolvimento isolado.
- 🎛️ **Dashboard Integrado:** Painel administrativo leve para visualizar o status dos circuitos, estatísticas de execução e realizar resets manuais.
- 🔀 **Fallback Handler Flexível:** Suporte a callbacks de fallback, classes dedicadas ou retorno de valores padrão configuráveis.
- 🧪 **Suíte Completa de Testes com Pest:** Testes unitários e de integração cobrindo 100% da máquina de estados do Circuit Breaker.
---

## 📦 Instalação

Instale o pacote em sua aplicação Laravel via Composer:

```bash
composer require resilience-kit/laravel-resilience-kit
```

Publique o arquivo de configuração e, opcionalmente, as views do painel (para customização):

```bash
php artisan vendor:publish --tag="resilience-kit-config"
php artisan vendor:publish --tag="resilience-kit-views"
```

---

## 🐳 Desenvolvimento com Laravel Sail (Recomendado)

Se você estiver desenvolvendo ou contribuindo diretamente no pacote, o **Laravel Sail** é a forma recomendada de subir o ambiente completo com PHP 8.3+ e Redis:

```bash
# Subir os contêineres Docker (PHP + Redis)
./vendor/bin/sail up -d

# Executar a suíte de testes unitários com Pest
./vendor/bin/sail pest

# Executar análise estática de código (Larastan/PHPStan)
./vendor/bin/sail exec laravel.test ./vendor/bin/phpstan analyse app

# Corrigir formatação de código (Laravel Pint)
./vendor/bin/sail pint
```

---

## ⚙️ Configuração Básica (`config/resilience-kit.php`)

```php
return [
    'default_driver' => env('RESILIENCE_DRIVER', 'redis'),

    'circuit_breaker' => [
        'sliding_window_size' => env('RESILIENCE_CB_SLIDING_WINDOW_SIZE', 10),
        'minimum_calls' => env('RESILIENCE_CB_MINIMUM_CALLS', 10),
        'failure_rate_threshold' => env('RESILIENCE_CB_FAILURE_RATE_THRESHOLD', 50), // %
        'recovery_timeout'  => env('RESILIENCE_CB_RECOVERY_TIMEOUT', 30), // segundos
        'half_open_trials'  => env('RESILIENCE_CB_HALF_OPEN_TRIALS', 3),
        'cache_store'        => env('RESILIENCE_CB_CACHE_STORE', 'redis'),
        'prefix'             => 'resilience:cb:',
    ],

    // Limites por circuito. Chaves omitidas herdam o default global acima.
    'circuits' => [
        'payment-gateway' => [
            'sliding_window_size' => 20,
            'minimum_calls' => 10,
            'failure_rate_threshold' => 50,
            'recovery_timeout' => 60,
            'half_open_trials' => 2,
        ],
    ],

    'dashboard' => [
        'enabled'    => env('RESILIENCE_DASHBOARD_ENABLED', true),
        'path'       => env('RESILIENCE_DASHBOARD_PATH', 'resilience/dashboard'),
        'middleware' => ['web', 'auth'],
    ],
];
```

Precedência ao criar um circuito: valor persistido pela API fluente ou `#[WithCircuitBreaker]` → `circuits.{name}` → defaults de `circuit_breaker`. O dashboard segue essa ordem. Em `run()`, o limite omitido na chamada deixa de ser persistido e volta ao config.

---

## 🚀 Uso Rápido: Circuit Breaker

### 1. Utilizando a Facade

Proteger chamadas HTTP externas ou operações críticas contra falhas em cascata. Com o circuito definido em `circuits`, basta o nome:

```php
use LaravelResilienceKit\Facades\Resilience;
use LaravelResilienceKit\CircuitBreaker\Exceptions\CircuitBreakerOpenException;

$result = Resilience::circuitBreaker('payment-gateway')
    ->fallback(fn (\Throwable $e) => ['status' => 'degraded', 'message' => 'Service temporarily unavailable'])
    ->run(function () {
        return Http::timeout(2)->post('https://api.example.com/v1/charge');
    });
```

Override persistido (aparece no dashboard; a próxima `run()` sem esses métodos volta ao config):

```php
Resilience::circuitBreaker('payment-gateway')
    ->slidingWindowSize(20)
    ->minimumCalls(10)
    ->failureRateThreshold(50)
    ->recoveryTimeout(15)
    ->run(...);
```

Demonstração em `routes/web.php`. Com janela 3, mínimo 3 e taxa 100%, três falhas seguidas abrem o circuito. `/demo/ok` grava um sucesso nessa mesma amostra:

```php
use LaravelResilienceKit\Facades\Resilience;

Route::get('/demo/ok', function () {
    return Resilience::circuitBreaker('payment-gateway')
        ->slidingWindowSize(3)
        ->minimumCalls(3)
        ->failureRateThreshold(100)
        ->recoveryTimeout(30)
        ->fallback(fn (Throwable $e) => 'fallback')
        ->run(fn () => 'sucesso');
});

Route::get('/demo/fail', function () {
    return Resilience::circuitBreaker('payment-gateway')
        ->slidingWindowSize(3)
        ->minimumCalls(3)
        ->failureRateThreshold(100)
        ->recoveryTimeout(30)
        ->fallback(fn (Throwable $e) => 'fallback: '.$e->getMessage())
        ->run(fn () => throw new RuntimeException('serviço fora do ar'));
});
```

### 2. Uso via Atributos PHP (PHP 8.2+)

Anote os métodos de Services (ou qualquer classe) e invoque-os através de `Resilience::protect()`:

```php
use LaravelResilienceKit\CircuitBreaker\Attributes\WithCircuitBreaker;
use LaravelResilienceKit\Facades\Resilience;

class PaymentService
{
    #[WithCircuitBreaker(name: 'payment-gateway', fallback: 'paymentFallback', slidingWindowSize: 20, minimumCalls: 10, failureRateThreshold: 50, recoveryTimeout: 60)]
    public function processPayment(array $payload)
    {
        // Chamada a serviço externo
    }

    // Recebe os mesmos argumentos da chamada original + a exceção.
    public function paymentFallback(array $payload, \Throwable $e)
    {
        return ['status' => 'queued_for_retry'];
    }
}

$payments = Resilience::protect(app(PaymentService::class));
$payments->processPayment($payload);   // protegido pelo circuito "payment-gateway"

// Ou, sem proxy:
Resilience::call($service, 'processPayment', [$payload]);
```

Parâmetros do atributo (todos opcionais): `name` (padrão `Classe::método`), `fallback` (método da classe ou classe de `FallbackHandlerInterface`), `slidingWindowSize`, `minimumCalls`, `failureRateThreshold`, `recoveryTimeout` e `halfOpenTrials` (quando omitidos, usam `circuits.{name}` ou os defaults de `circuit_breaker`).

> ⚠️ Atributos PHP são apenas metadados: a proteção só é aplicada nas chamadas feitas **através do proxy** (`protect`) ou de `Resilience::call()`. O proxy **não** é uma instância da classe original (não satisfaz type hints); use `$payments->__unwrap()` para obter o objeto real. Chamadas diretas ao objeto original não são protegidas.

---

## 🧪 Testes Unitários

Executar a suíte completa de testes unitários e de integração:

```bash
# Via Sail
./vendor/bin/sail pest

# Com relatório de cobertura
./vendor/bin/sail pest --coverage
```

---

## 📊 Painel de Monitoramento (Dashboard)

Acesse o painel integrado navegando para `/resilience/dashboard` (ou a rota definida em seu `.env`).

- **Visão Geral de Circuitos:** Acompanhe o estado atual de cada circuito registrado (`CLOSED`, `OPEN`, `HALF-OPEN`), com atualização automática a cada 5 segundos.
- **Métricas:** Amostra da janela (falhas/chamadas e taxa), política (`failure_rate_threshold`, volume mínimo, tamanho da janela), provas de HALF-OPEN, `recovery_timeout`, horário da última abertura, motivo da última falha e tempo restante até a tentativa de recuperação.
- **Ações Imediatas:** Botão de **Reset** para fechar um circuito aberto e botão de **Remove** (com confirmação) para tirá-lo do painel e apagar o estado persistido.

### API JSON

| Método | Rota | Descrição |
|--------|------|-----------|
| `GET`  | `/resilience/dashboard` | Painel (view Blade) |
| `GET`  | `/resilience/api/circuits` | Lista de circuitos e métricas |
| `POST` | `/resilience/api/circuits/{name}/reset` | Reset manual (`404` se o circuito não existir) |
| `DELETE` | `/resilience/api/circuits/{name}` | Remove o circuito do painel (`404` se não existir). A próxima chamada pode registrá-lo de novo |

> A amostra é a janela das últimas chamadas. O circuito abre quando ela tem o volume mínimo e a taxa de falha atinge o limite. Os limites exibidos (`sliding_window_size`, `minimum_calls`, `failure_rate_threshold`, `recovery_timeout`, `half_open_trials`) seguem o último valor fluente persistido e, para o que não foi definido, `circuits.{name}` ou os defaults de `circuit_breaker`. Uma `run()` que omite o método faz o painel voltar ao config.

### Autenticação e autorização

As rotas usam os middlewares de `resilience-kit.dashboard.middleware` (padrão `web`, `auth`) e, adicionalmente, o middleware `AuthorizeDashboard`: se a aplicação definir o gate `viewResilienceDashboard`, ele decide o acesso; sem o gate, o acesso só é liberado nos ambientes `local` e `testing`.

```php
// AppServiceProvider::boot()
Gate::define('viewResilienceDashboard', fn ($user) => $user->isAdmin());
```

---

## 📄 Licença

O pacote **Laravel Resilience Kit** é um software open-source licenciado sob a [MIT License](LICENSE.md).
