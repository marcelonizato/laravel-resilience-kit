# Especificação Técnica: Circuit Breaker, Unit Testing & Dashboard

Este documento contém a especificação detalhada de implementação para o módulo de **Circuit Breaker**, a suíte de **Testes Unitários** e o **Painel de Monitoramento (Dashboard)** do pacote `Laravel Resilience Kit`, integrado com o ambiente **Laravel Sail**.

---

## ⚙️ 1. Máquina de Estados do Circuit Breaker

O Circuit Breaker opera sob 3 estados fundamentais:

```
    ┌──────────┐     Threshold de Falhas Atingido     ┌──────────┐
    │          ├─────────────────────────────────────►│          │
    │  CLOSED  │                                      │   OPEN   │
    │          │◄─────────────────────────────────────┤          │
    └────▲─────┘          Recuperação Bem-Sucedida    └────┬─────┘
         │                                                 │
         │                ┌───────────┐                    │
         └────────────────┤   HALF-   │◄───────────────────┘
          Sucesso em      │   OPEN    │   Recovery Timeout Expirado
          N requisições   └─────┬─────┘
                                │ Falha em qualquer
                                ▼ requisição de teste
                           (Retorna para OPEN)
```

### Regras Detalhadas de Transição:

1. **CLOSED (Fechado - Operação Normal):**
   - Requisições executam a ação encapsulada normalmente.
   - Exceções incrementam o contador de falhas (`failures_count`).
   - Se `failures_count >= failure_threshold`, o estado transita para `OPEN`, registra `last_tripped_at` e dispara o evento `CircuitOpened`.

2. **OPEN (Aberto - Curto-Circuito Ativo):**
   - Requisições não chegam ao serviço protegido; falham instantaneamente lançando `CircuitBreakerOpenException` ou executando o `fallback`.
   - Permanece em `OPEN` durante o intervalo de tempo `recovery_timeout` (segundos).
   - Após a expiração de `recovery_timeout`, a próxima requisição altera o estado para `HALF-OPEN`.

3. **HALF-OPEN (Meio-Aberto - Teste de Recuperação):**
   - Aceita um número limitado de requisições de teste (`half_open_trials`, padrão: 3).
   - Se todas as `half_open_trials` forem bem-sucedidas: o contador de falhas é zerado, o circuito retorna a `CLOSED` e dispara o evento `CircuitClosed`.
   - Se ocorrer **qualquer falha** no estado `HALF-OPEN`: o circuito volta imediatamente para `OPEN`, reinicia o timer de `recovery_timeout` e dispara o evento `CircuitOpened`.

---

## 🗄️ 2. Estrutura de Dados & Atomicidade (Redis / Cache)

Para suportar alta concorrência sem *race conditions* em ambiente distribuído:

### Chaves no Redis / Cache Store:
- `resilience:cb:{circuit_name}:state` (string: `closed` | `open` | `half_open`)
- `resilience:cb:{circuit_name}:failures` (int - atômico via INCR)
- `resilience:cb:{circuit_name}:half_open_successes` (int - atômico via INCR)
- `resilience:cb:{circuit_name}:last_tripped_at` (timestamp UNIX)
- `resilience:cb:{circuit_name}:last_failure_reason` (string com a mensagem da exceção)

---

## 🧪 3. Especificação de Testes Unitários e de Integração (Pest PHP)

Toda a lógica da máquina de estados e do armazenamento DEVE possuir cobertura de testes unitários executáveis via `./vendor/bin/sail pest`.

### A. Cenários Obrigatórios de Testes Unitários (`tests/Unit/CircuitBreakerTest.php`):

1. **Testes do Estado CLOSED:**
   - Deve retornar o resultado correto da ação no caminho feliz.
   - Deve incrementar o contador de falhas ao capturar exceção.
   - Não deve abrir o circuito se as falhas estiverem abaixo do `failure_threshold`.

2. **Testes do Estado OPEN e Fallbacks:**
   - Deve transitar para `OPEN` exatamente ao atingir o `failure_threshold`.
   - Deve lançar `CircuitBreakerOpenException` quando o circuito estiver aberto e nenhum fallback for fornecido.
   - Deve executar o callback de `fallback` quando fornecido via Closure ou Classe Handler.
   - Deve disparar o evento `CircuitOpened` na transição.

3. **Testes do Estado HALF-OPEN e Recuperação:**
   - Deve transitar de `OPEN` para `HALF-OPEN` após a passagem do `recovery_timeout`.
   - Deve redefinir o estado para `CLOSED` e zerar falhas após `half_open_trials` requisições bem-sucedidas.
   - Deve reabrir o circuito imediatamente se uma requisição falhar durante o estado `HALF-OPEN`.

4. **Testes de Reset Manual:**
   - Chamada do método `reset()` deve forçar o estado para `CLOSED` e zerar todos os contadores.

### B. Exemplo de Código do Teste Unitário (Pest):

```php
<?php

declare(strict_types=1);

use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;
use LaravelResilienceKit\CircuitBreaker\Exceptions\CircuitBreakerOpenException;
use LaravelResilienceKit\Facades\Resilience;

it('executes fallback and blocks execution when circuit is open', function () {
    $circuit = Resilience::circuitBreaker('external-api')
        ->failureThreshold(1)
        ->recoveryTimeout(60)
        ->fallback(fn (\Throwable $e) => 'fallback-data');

    // Provoca a falha para abrir o circuito
    try {
        $circuit->run(fn () => throw new RuntimeException('Connection failed'));
    } catch (Throwable $e) {}

    expect($circuit->getState())->toBe(CircuitState::OPEN);

    // Próxima execução deve acionar o fallback sem invocar o closure original
    $wasExecuted = false;
    $response = $circuit->run(function () use (&$wasExecuted) {
        $wasExecuted = true;
        return 'normal-data';
    });

    expect($wasExecuted)->toBeFalse()
        ->and($response)->toBe('fallback-data');
});
```

---

## 🐳 4. Configuração de Testes com Laravel Sail & Docker

A suíte de testes de integração utiliza o container de Redis fornecido pelo Sail:

### Snippet do `docker-compose.yml` do Sail:
```yaml
version: '3.8'
services:
  laravel.test:
    build:
      context: ./vendor/laravel/sail/runtimes/8.3
      dockerfile: Dockerfile
    image: sail-8.3/app
    extra_hosts:
      - 'host.docker.internal:host-gateway'
    ports:
      - '${APP_PORT:-80}:80'
    environment:
      WWWUSER: '${WWWUSER}'
      LARAVEL_SAIL: 1
      XDEBUG_MODE: '${SAIL_XDEBUG_MODE:-off}'
    volumes:
      - '.:/var/www/html'
    networks:
      - sail
    depends_on:
      - redis

  redis:
    image: 'redis:alpine'
    ports:
      - '${FORWARD_REDIS_PORT:-6379}:6379'
    volumes:
      - 'sail-redis:/data'
    networks:
      - sail
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      retries: 3
      timeout: 5s

networks:
  sail:
    driver: bridge

volumes:
  sail-redis:
    driver: local
```

---

## 📊 5. Painel Administrativo & Dashboard Spec

### Rotas e Endpoints:
- `GET /resilience/dashboard` — View principal com tabela interativa de circuitos.
- `GET /resilience/api/circuits` — Retorna JSON com estado, contadores de falha/sucesso e métricas de todos os circuitos.
- `POST /resilience/api/circuits/{name}/reset` — Executa o reset atômico do circuito informado e retorna status 200 JSON.

### Testes da API do Dashboard (`tests/Feature/DashboardApiTest.php`):
```php
<?php

declare(strict_types=1);

it('can fetch circuit status list via dashboard api', function () {
    Resilience::circuitBreaker('payment-service')->getState();

    $this->getJson('/resilience/api/circuits')
        ->assertStatus(200)
        ->assertJsonStructure([
            'circuits' => [
                '*' => ['name', 'state', 'failures_count', 'failure_threshold', 'recovery_timeout']
            ]
        ]);
});

it('can manually reset a circuit via dashboard api', function () {
    $cb = Resilience::circuitBreaker('order-service')->failureThreshold(1);
    
    try {
        $cb->run(fn () => throw new Exception('Error'));
    } catch (Throwable $e) {}

    $this->postJson('/resilience/api/circuits/order-service/reset')
        ->assertStatus(200)
        ->assertJson(['message' => 'Circuit order-service reset successfully']);

    expect($cb->getState())->toBe(\LaravelResilienceKit\CircuitBreaker\Enums\CircuitState::CLOSED);
});
```
