<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Las pruebas con `RefreshDatabase` borran y recrean todas las tablas. Con PostgreSQL (`phpunit.pgsql.xml`) eso solo puede ocurrir en una
     * base cuyo nombre termine en `_test`: si por una configuración equivocada apuntaran a la base real, se detienen antes de tocar nada.
     */
    protected function setUpTraits()
    {
        if (DB::getDriverName() === 'pgsql' && ! str_ends_with((string) DB::getDatabaseName(), '_test')) {
            throw new RuntimeException('Las pruebas se negaron a borrar la base «'.DB::getDatabaseName().'»: con PostgreSQL solo usan una base cuyo nombre termina en _test.');
        }

        return parent::setUpTraits();
    }
}
