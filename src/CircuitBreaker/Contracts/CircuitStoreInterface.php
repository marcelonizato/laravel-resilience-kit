<?php

declare(strict_types=1);

namespace LaravelResilienceKit\CircuitBreaker\Contracts;

use LaravelResilienceKit\CircuitBreaker\Enums\CircuitState;
use LaravelResilienceKit\CircuitBreaker\Support\CallWindow;

/**
 * Contrato de persistência do estado dos circuitos.
 *
 * Todas as operações que alteram estado DEVEM ser atômicas, inclusive entre
 * processos/servidores distintos (zero race conditions). Em particular:
 *
 *  - {@see self::recordOutcome()} grava o resultado na janela deslizante e
 *    devolve a amostra resultante, numa única operação;
 *  - {@see self::incrementHalfOpenSuccesses()} é um incremento atômico que
 *    retorna o novo valor;
 *  - {@see self::transitionState()} é um compare-and-set: apenas UM chamador
 *    concorrente vence a transição, e somente ele deve disparar o evento
 *    correspondente (CircuitOpened, CircuitClosed, CircuitHalfOpened).
 *
 * Efeitos colaterais aplicados atomicamente junto com a mudança de estado:
 *
 *  - para OPEN: grava `last_tripped_at` (timestamp UNIX atual) e zera os
 *    sucessos de half-open (a janela é preservada, para o dashboard mostrar
 *    a amostra que abriu o circuito);
 *  - para HALF_OPEN: zera os sucessos de half-open;
 *  - para CLOSED: apaga a janela e os sucessos de half-open.
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
     * Grava um resultado na janela das últimas `$windowSize` chamadas.
     *
     * O resultado mais antigo sai quando a janela enche. Um sucesso não apaga
     * as falhas que continuam dentro da janela. `$reason` só é gravado quando
     * `$failure` é verdadeiro e a string não é vazia.
     *
     * @param  int  $windowSize  Tamanho máximo da janela. Valores abaixo de 1 são tratados como 1.
     */
    public function recordOutcome(string $circuit, bool $failure, int $windowSize, ?string $reason = null): CallWindow;

    /**
     * Lê a janela, aparando-a a `$windowSize` quando a configuração encolheu.
     *
     * Não registra o circuito. Uma janela inexistente é vazia.
     *
     * @param  int  $windowSize  Tamanho máximo considerado. Valores abaixo de 1 são tratados como 1.
     */
    public function getWindow(string $circuit, int $windowSize): CallWindow;

    /**
     * Registra o motivo de uma falha sem alterá-la na janela.
     *
     * Usado nas provas de HALF-OPEN, que não entram na amostra de CLOSED.
     */
    public function rememberFailureReason(string $circuit, string $reason): void;

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
     * Reset completo: remove estado, janela e contadores (o circuito volta a CLOSED).
     *
     * O circuito permanece registrado no índice e os limites configurados
     * ({@see self::setSlidingWindowSize()}, {@see self::setMinimumCalls()},
     * {@see self::setFailureRateThreshold()}, {@see self::setRecoveryTimeout()},
     * {@see self::setHalfOpenTrials()}) são preservados.
     */
    public function reset(string $circuit): void;

    /**
     * Remove o circuito do índice e apaga estado, janela, contadores e limites.
     *
     * Diferente de {@see self::reset()}, o circuito deixa de aparecer na
     * listagem. Uma chamada posterior a {@see self::register()} o recria.
     */
    public function forget(string $circuit): void;

    /**
     * Persiste o tamanho da janela deslizante.
     *
     * Sobrevive a {@see self::reset()}. `null` em {@see self::getSlidingWindowSize()}
     * significa que o circuito ainda usa o padrão da configuração.
     */
    public function setSlidingWindowSize(string $circuit, int $size): void;

    /**
     * Tamanho da janela persistido, ou `null` se o circuito nunca o definiu.
     */
    public function getSlidingWindowSize(string $circuit): ?int;

    /**
     * Apaga o tamanho da janela persistido. O circuito volta ao valor do config.
     */
    public function forgetSlidingWindowSize(string $circuit): void;

    /**
     * Persiste o volume mínimo de chamadas antes de avaliar a taxa de falha.
     *
     * Sobrevive a {@see self::reset()}.
     */
    public function setMinimumCalls(string $circuit, int $calls): void;

    /**
     * Volume mínimo persistido, ou `null` se o circuito nunca o definiu.
     */
    public function getMinimumCalls(string $circuit): ?int;

    /**
     * Apaga o volume mínimo persistido. O circuito volta ao valor do config.
     */
    public function forgetMinimumCalls(string $circuit): void;

    /**
     * Persiste a taxa de falha, em porcentagem inteira de 1 a 100, que abre o circuito.
     *
     * Sobrevive a {@see self::reset()}.
     */
    public function setFailureRateThreshold(string $circuit, int $percentage): void;

    /**
     * Taxa de falha persistida, ou `null` se o circuito nunca a definiu.
     */
    public function getFailureRateThreshold(string $circuit): ?int;

    /**
     * Apaga a taxa de falha persistida. O circuito volta ao valor do config.
     */
    public function forgetFailureRateThreshold(string $circuit): void;

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
