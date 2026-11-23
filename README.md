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
        'failure_threshold' => env('RESILIENCE_CB_FAILURE_THRESHOLD', 5),
        'recovery_timeout'  => env('RESILIENCE_CB_RECOVERY_TIMEOUT', 30), // segundos
        'half_open_trials'  => env('RESILIENCE_CB_HALF_OPEN_TRIALS', 3),
        'cache_store'        => env('RESILIENCE_CB_CACHE_STORE', 'redis'),
        'prefix'             => 'resilience:cb:',
    ],

    // Limites por circuito. Chaves omitidas herdam o default global acima.
    'circuits' => [
        'payment-gateway' => [
            'failure_threshold' => 3,
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

Precedência dos limites: defaults de `circuit_breaker` → `circuits.{name}` → API fluente / `#[WithCircuitBreaker]` (persiste no store e vence).

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

Override pontual (vence o config e persiste no store):

```php
Resilience::circuitBreaker('payment-gateway')
    ->failureThreshold(1)
    ->recoveryTimeout(15)
    ->run(...);
```

### 2. Uso via Atributos PHP (PHP 8.2+)

Anote os métodos de Services (ou qualquer classe) e invoque-os através de `Resilience::protect()`:

```php
use LaravelResilienceKit\CircuitBreaker\Attributes\WithCircuitBreaker;
use LaravelResilienceKit\Facades\Resilience;

class PaymentService
{
    #[WithCircuitBreaker(name: 'payment-gateway', fallback: 'paymentFallback', failureThreshold: 3, recoveryTimeout: 60)]
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

Parâmetros do atributo (todos opcionais): `name` (padrão `Classe::método`), `fallback` (método da classe ou classe de `FallbackHandlerInterface`), `failureThreshold`, `recoveryTimeout` e `halfOpenTrials` (quando omitidos, usam `circuits.{name}` ou os defaults de `circuit_breaker`).

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
- **Métricas:** Contador de falhas (vs. limite), `recovery_timeout`, horário da última abertura, motivo da última falha e tempo restante até a tentativa de recuperação.
- **Ações Imediatas:** Botão de **Reset Manual** para fechar instantaneamente um circuito aberto após manutenção no serviço dependente.

### API JSON

| Método | Rota | Descrição |
|--------|------|-----------|
| `GET`  | `/resilience/dashboard` | Painel (view Blade) |
| `GET`  | `/resilience/api/circuits` | Lista de circuitos e métricas |
| `POST` | `/resilience/api/circuits/{name}/reset` | Reset manual (`404` se o circuito não existir) |

> Os limites exibidos (`failure_threshold`, `recovery_timeout`, `half_open_trials`) seguem a precedência store (API fluente/atributo) → `circuits.{name}` → defaults de `circuit_breaker`. O reset manual preserva overrides persistidos no store.

### Autenticação e autorização

As rotas usam os middlewares de `resilience-kit.dashboard.middleware` (padrão `web`, `auth`) e, adicionalmente, o middleware `AuthorizeDashboard`: se a aplicação definir o gate `viewResilienceDashboard`, ele decide o acesso; sem o gate, o acesso só é liberado nos ambientes `local` e `testing`.

```php
// AppServiceProvider::boot()
Gate::define('viewResilienceDashboard', fn ($user) => $user->isAdmin());
```

---

## 📄 Licença

O pacote **Laravel Resilience Kit** é um software open-source licenciado sob a [MIT License](LICENSE.md).
