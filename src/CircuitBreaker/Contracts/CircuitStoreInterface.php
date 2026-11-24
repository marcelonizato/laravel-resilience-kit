<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Contracts;

use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;

/**
 * Contrato de persistência do estado dos circuitos.
 *
 * Todas as operações que alteram estado DEVEM ser atômicas, inclusive entre
 * processos/servidores distintos (zero race conditions). Em particular:
 *
 *  - {@see self::incrementFailures()} e {@see self::incrementHalfOpenSuccesses()}
 *    são incrementos atômicos que retornam o novo valor;
 *  - {@see self::transitionState()} é um compare-and-set: apenas UM chamador
 *    concorrente vence a transição, e somente ele deve disparar o evento
 *    correspondente (CircuitOpened, CircuitClosed, CircuitHalfOpened).
 *
 * Efeitos colaterais aplicados atomicamente junto com a mudança de estado:
 *
 *  - para OPEN: grava `last_tripped_at` (timestamp UNIX atual) e zera os
 *    sucessos de half-open (o contador de falhas é preservado);
 *  - para HALF_OPEN: zera os sucessos de half-open;
 *  - para CLOSED: zera o contador de falhas e os sucessos de half-open.
 *
 * Um circuito sem dados persistidos está em {@see CircuitState::CLOSED}.
 */
interface CircuitStoreInterface
{
    /**
     * Registra o circuito no índice de circuitos conhecidos (idempotente).
     *
     * Permite que o dashboard liste circuitos que ainda não sofreram nenhuma
     * escrita de estado.
     */
    public function register(string $circuit): void;

    /**
     * Lista os nomes dos circuitos registrados, em ordem alfabética.
     *
     * @return list<string>
     */
    public function circuits(): array;

    /**
     * Retorna o estado atual do circuito (CLOSED quando inexistente).
     */
    public function getState(string $circuit): CircuitState;

    /**
     * Compare-and-set atômico do estado.
     *
     * @param  CircuitState  $from  Estado esperado.
     * @param  CircuitState  $to  Novo estado.
     * @return bool `true` se este chamador efetuou a transição; `false` se o
     *              estado atual não era `$from` (outro processo venceu).
     */
    public function transitionState(string $circuit, CircuitState $from, CircuitState $to): bool;

    /**
     * Força o estado de forma incondicional (trip/reset manual), aplicando os
     * mesmos efeitos colaterais de {@see self::transitionState()}.
     */
    public function forceState(string $circuit, CircuitState $state): void;

    /**
     * Incrementa atomicamente o contador de falhas e, se informado, registra o
     * motivo da falha.
     *
     * @return int Novo valor do contador de falhas.
     */
    public function incrementFailures(string $circuit, ?string $reason = null): int;

    /**
     * Retorna o contador de falhas atual.
     */
    public function getFailures(string $circuit): int;

    /**
     * Incrementa atomicamente o contador de sucessos em HALF-OPEN.
     *
     * @return int Novo valor do contador de sucessos.
     */
    public function incrementHalfOpenSuccesses(string $circuit): int;

    /**
     * Retorna o contador de sucessos em HALF-OPEN.
     */
    public function getHalfOpenSuccesses(string $circuit): int;

    /**
     * Timestamp UNIX da última abertura do circuito, ou `null` se nunca abriu.
     */
    public function getLastTrippedAt(string $circuit): ?int;

    /**
     * Mensagem da última exceção registrada, ou `null` se não houver.
     */
    public function getLastFailureReason(string $circuit): ?string;

    /**
     * Reset completo: remove estado e contadores (o circuito volta a CLOSED).
     *
     * O circuito permanece registrado no índice e os limites configurados
     * ({@see self::setFailureThreshold()}, {@see self::setRecoveryTimeout()},
     * {@see self::setHalfOpenTrials()}) são preservados.
     */
    public function reset(string $circuit): void;

    /**
     * Remove o circuito do índice e apaga estado, contadores e limites.
     *
     * Diferente de {@see self::reset()}, o circuito deixa de aparecer na
     * listagem. Uma chamada posterior a {@see self::register()} o recria.
     */
    public function forget(string $circuit): void;

    /**
     * Persiste o limite de falhas do circuito.
     *
     * Sobrevive a {@see self::reset()}. `null` em {@see self::getFailureThreshold()}
     * significa que o circuito ainda usa o padrão da configuração.
     */
    public function setFailureThreshold(string $circuit, int $threshold): void;

    /**
     * Limite de falhas persistido, ou `null` se o circuito nunca o definiu.
     */
    public function getFailureThreshold(string $circuit): ?int;

    /**
     * Apaga o limite de falhas persistido. O circuito volta ao valor do config.
     */
    public function forgetFailureThreshold(string $circuit): void;

    /**
     * Persiste o timeout de recuperação, em segundos.
     *
     * Sobrevive a {@see self::reset()}.
     */
    public function setRecoveryTimeout(string $circuit, int $seconds): void;

    /**
     * Timeout de recuperação persistido, em segundos, ou `null` se indefinido.
     */
    public function getRecoveryTimeout(string $circuit): ?int;

    /**
     * Apaga o timeout de recuperação persistido. O circuito volta ao valor do config.
     */
    public function forgetRecoveryTimeout(string $circuit): void;

    /**
     * Persiste a quantidade de sucessos em HALF-OPEN necessária para fechar.
     *
     * Sobrevive a {@see self::reset()}.
     */
    public function setHalfOpenTrials(string $circuit, int $trials): void;

    /**
     * Quantidade de trials persistida, ou `null` se o circuito nunca a definiu.
     */
    public function getHalfOpenTrials(string $circuit): ?int;

    /**
     * Apaga a quantidade de trials persistida. O circuito volta ao valor do config.
     */
    public function forgetHalfOpenTrials(string $circuit): void;
}
