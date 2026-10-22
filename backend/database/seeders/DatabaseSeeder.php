<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Catálogo base: necesario en todos los entornos.
        $this->call(RolePermissionSeeder::class);

        // Datos ficticios: nunca en producción.
        if (! app()->isProduction()) {
            $this->call(DevDataSeeder::class);
        }
    }
}
