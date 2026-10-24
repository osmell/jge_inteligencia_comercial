<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Falla si PHP es anterior a las versiones que corrigen CVE-2026-109618 y CVE-2026-109619 (PHP2Shell, 2026-10-21).
 * Corre en la CI y dentro del contenedor, así que una imagen vieja o un PHP sin parchar no pasa desapercibido.
 */
class PhpVersionTest extends TestCase
{
    public function test_php_includes_the_php2shell_fix(): void
    {
        $minimums = ['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'];
        $branch = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        $this->assertArrayHasKey($branch, $minimums, "PHP {$branch} no recibe la corrección de PHP2Shell: migre a 8.5.");
        $this->assertTrue(
            version_compare(PHP_VERSION, $minimums[$branch], '>='),
            'PHP '.PHP_VERSION." es vulnerable a PHP2Shell; actualice a {$minimums[$branch]} o superior."
        );
    }
}
