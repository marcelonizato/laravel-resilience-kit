<?php

declare(strict_types=1);

/**
 * Worker de concorrência executado como processo PHP independente.
 *
 * Uso: php redis_worker.php <modo> <prefixo> <circuito> <startAt> [iterações]
 *
 * Todos os workers aguardam o mesmo instante (`startAt`, em microtime) para
 * disputar o Redis simultaneamente.
 *
 * Modos:
 *  - cas  : tenta CLOSED -> OPEN uma vez e imprime `1` (venceu) ou `0`.
 *  - incr : incrementa falhas N vezes e imprime os valores retornados (CSV).
 */

use LaravelResilienceKit\CircuitBreaker\Drivers\RedisCircuitStore;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;
use LaravelResilienceKit\Tests\Support\StoreFactory;

require __DIR__.'/../../vendor/autoload.php';

[, $mode, $prefix, $circuit, $startAt] = $argv;
$iterations = (int) ($argv[5] ?? 1);

$store = new RedisCircuitStore(StoreFactory::rawConnection(), $prefix);

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

if ($mode === 'cas') {
    echo $store->transitionState($circuit, CircuitState::CLOSED, CircuitState::OPEN) ? '1' : '0';

    exit(0);
}

$values = [];

for ($i = 0; $i < $iterations; $i++) {
    $values[] = $store->incrementFailures($circuit, 'worker');
}

echo implode(',', $values);
