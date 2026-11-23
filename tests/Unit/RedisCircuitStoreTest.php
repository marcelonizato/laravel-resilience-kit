<?php

declare(strict_types=1);

use Illuminate\Redis\Connections\Connection;
use LaravelResilienceKit\CircuitBreaker\Drivers\RedisCircuitStore;
use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;
use LaravelResilienceKit\Tests\Support\StoreFactory;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Testes com Redis real (ignorados se o Redis/phpredis estiver indisponível)
|--------------------------------------------------------------------------
*/

it('persists data using the keys defined in the spec', function (): void {
    skipUnlessRedis();

    $prefix = StoreFactory::uniquePrefix();
    $store = StoreFactory::makeRedis($prefix);
    $redis = StoreFactory::rawConnection();

    $store->incrementFailures('payments', 'timeout');
    $store->incrementHalfOpenSuccesses('payments');
    $store->setFailureThreshold('payments', 3);
    $store->setRecoveryTimeout('payments', 60);
    $store->setHalfOpenTrials('payments', 2);
    $store->forceState('payments', CircuitState::OPEN);

    expect($redis->command('get', ["{$prefix}payments:state"]))->toBe('open')
        ->and((int) $redis->command('get', ["{$prefix}payments:failures"]))->toBe(1)
        ->and((int) $redis->command('get', ["{$prefix}payments:half_open_successes"]))->toBe(0)
        ->and((int) $redis->command('get', ["{$prefix}payments:last_tripped_at"]))->toBeGreaterThan(0)
        ->and($redis->command('get', ["{$prefix}payments:last_failure_reason"]))->toBe('timeout')
        ->and((int) $redis->command('get', ["{$prefix}payments:failure_threshold"]))->toBe(3)
        ->and((int) $redis->command('get', ["{$prefix}payments:recovery_timeout"]))->toBe(60)
        ->and((int) $redis->command('get', ["{$prefix}payments:half_open_trials"]))->toBe(2)
        ->and($redis->command('sismember', ["{$prefix}circuits", 'payments']))->toBeTruthy();
});

it('removes state keys on reset and keeps the configured limits', function (): void {
    skipUnlessRedis();

    $prefix = StoreFactory::uniquePrefix();
    $store = StoreFactory::makeRedis($prefix);
    $redis = StoreFactory::rawConnection();

    $store->incrementFailures('payments', 'timeout');
    $store->setFailureThreshold('payments', 3);
    $store->setRecoveryTimeout('payments', 60);
    $store->setHalfOpenTrials('payments', 2);
    $store->forceState('payments', CircuitState::OPEN);
    $store->reset('payments');

    foreach (['state', 'failures', 'half_open_successes', 'last_tripped_at', 'last_failure_reason'] as $suffix) {
        expect((int) $redis->command('exists', ["{$prefix}payments:{$suffix}"]))->toBe(0);
    }

    expect((int) $redis->command('get', ["{$prefix}payments:failure_threshold"]))->toBe(3)
        ->and((int) $redis->command('get', ["{$prefix}payments:recovery_timeout"]))->toBe(60)
        ->and((int) $redis->command('get', ["{$prefix}payments:half_open_trials"]))->toBe(2);
});

/**
 * Executa N processos PHP simultâneos contra o mesmo circuito.
 *
 * @return list<string> A saída (stdout) de cada worker.
 */
function runRedisWorkers(int $workers, string $mode, string $prefix, string $circuit, int $iterations = 1): array
{
    $startAt = microtime(true) + 1.5;
    $script = dirname(__DIR__).'/Support/redis_worker.php';
    $env = ['REDIS_HOST' => StoreFactory::host(), 'REDIS_PORT' => (string) StoreFactory::port()];

    $processes = [];

    for ($i = 0; $i < $workers; $i++) {
        $process = new Process(
            [PHP_BINARY, $script, $mode, $prefix, $circuit, (string) $startAt, (string) $iterations],
            env: $env,
        );
        $process->start();
        $processes[] = $process;
    }

    $outputs = [];

    foreach ($processes as $process) {
        $process->wait();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        $outputs[] = trim($process->getOutput());
    }

    return $outputs;
}

it('lets exactly one concurrent process win the CLOSED -> OPEN transition', function (): void {
    skipUnlessRedis();

    $prefix = StoreFactory::uniquePrefix();
    $store = StoreFactory::makeRedis($prefix);

    $outputs = runRedisWorkers(8, 'cas', $prefix, 'race');

    // Apenas um worker venceu ("1"); os outros sete perderam ("0"), em qualquer ordem.
    expect(array_count_values($outputs))->toEqual([1 => 1, 0 => 7])
        ->and($store->getState('race'))->toBe(CircuitState::OPEN);
});

it('does not lose or duplicate failure increments under concurrency', function (): void {
    skipUnlessRedis();

    $prefix = StoreFactory::uniquePrefix();
    $store = StoreFactory::makeRedis($prefix);

    $outputs = runRedisWorkers(8, 'incr', $prefix, 'counter', iterations: 50);

    $values = array_map(intval(...), explode(',', implode(',', $outputs)));
    sort($values);

    expect($values)->toBe(range(1, 400))
        ->and($store->getFailures('counter'))->toBe(400)
        ->and($store->getLastFailureReason('counter'))->toBe('worker');
});

/*
|--------------------------------------------------------------------------
| Testes com conexão simulada (não dependem de Redis)
|--------------------------------------------------------------------------
*/

it('sends EVAL in the predis format (script, numKeys, ...keys, ...args) for non-phpredis clients', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('command')->once()->with('sadd', ['resilience:cb:circuits', 'svc']);
    $connection->shouldReceive('command')
        ->once()
        ->withArgs(fn (string $command, array $parameters): bool => $command === 'eval'
            && is_string($parameters[0]) && str_contains($parameters[0], "redis.call('INCR'")
            && array_slice($parameters, 1) === [
                2,
                'resilience:cb:svc:failures',
                'resilience:cb:svc:last_failure_reason',
                'timeout',
            ])
        ->andReturn(3);

    expect((new RedisCircuitStore($connection))->incrementFailures('svc', 'timeout'))->toBe(3);
});

it('reports a won transition only when the script returns 1', function (mixed $scriptResult, bool $expected): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('command')->with('sadd', Mockery::any());
    $connection->shouldReceive('command')->with('eval', Mockery::any())->andReturn($scriptResult);

    $store = new RedisCircuitStore($connection);

    expect($store->transitionState('svc', CircuitState::CLOSED, CircuitState::OPEN))->toBe($expected);
})->with([
    'integer 1 (won)' => [1, true],
    'string "1" (won)' => ['1', true],
    'integer 0 (lost)' => [0, false],
    'unexpected value' => [null, false],
]);

it('treats a non-array SMEMBERS reply as no circuits', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('command')->with('smembers', ['resilience:cb:circuits'])->andReturn(false);

    expect((new RedisCircuitStore($connection))->circuits())->toBe([]);
});

it('normalizes missing keys (false) to defaults', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('command')->with('get', Mockery::any())->andReturn(false);

    $store = new RedisCircuitStore($connection);

    expect($store->getState('svc'))->toBe(CircuitState::CLOSED)
        ->and($store->getFailures('svc'))->toBe(0)
        ->and($store->getLastTrippedAt('svc'))->toBeNull()
        ->and($store->getLastFailureReason('svc'))->toBeNull();
});
