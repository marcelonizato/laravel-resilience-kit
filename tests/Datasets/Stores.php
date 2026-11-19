<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Datasets de drivers
|--------------------------------------------------------------------------
|
| Nomes dos drivers submetidos ao teste de contrato (CircuitStoreContractTest).
| O driver "redis" é ignorado (skip) quando não há Redis acessível.
|
*/

dataset('stores', ['array', 'cache', 'redis']);
