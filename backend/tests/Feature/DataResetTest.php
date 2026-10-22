<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Role;
use App\Models\SalesDocument;
use App\Models\Seller;
use App\Models\User;
use App\Services\DataReset;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** `php artisan data:reset`: deja solo el maestro, las cuentas y la configuración. */
class DataResetTest extends TestCase
{
    use RefreshDatabase;

    private function populate(): void
    {
        $this->seed(RolePermissionSeeder::class);
        User::factory()->role(Role::ADMINISTRADOR)->create();
        $document = SalesDocument::factory()->create();
        Customer::create(['company_id' => $document->company_id, 'code' => 'C1', 'name' => 'Cliente']);
        DB::table('products')->insert(['company_id' => $document->company_id, 'code' => 'P1', 'name' => 'Producto', 'created_at' => now(), 'updated_at' => now()]);
        Storage::fake('quotations')->put('2026/cotizacion.pdf', 'x');
    }

    public function test_every_table_is_classified_as_kept_or_wiped(): void
    {
        $this->assertSame([], (new DataReset)->unclassified(), 'Tabla nueva sin clasificar en DataReset: ¿se conserva o se borra?');
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->populate();

        $this->artisan('data:reset', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, SalesDocument::count());
        $this->assertSame(1, Customer::count());
    }

    public function test_it_wipes_operations_and_keeps_the_master_data_accounts_and_settings(): void
    {
        $this->populate();
        $users = User::count();
        DB::table('app_settings')->insert(['key' => 'k', 'value' => 'v', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('data:reset', ['--confirm' => DB::connection()->getDatabaseName()])->assertSuccessful();

        foreach (['sales_documents', 'sales_lines', 'customers', 'import_batches'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "$table debía quedar vacía.");
        }
        $this->assertGreaterThan(0, Company::count());
        $this->assertGreaterThan(0, Branch::count());
        $this->assertGreaterThan(0, Seller::count());
        $this->assertGreaterThan(0, Operator::count());
        $this->assertSame(1, DB::table('products')->count());
        $this->assertSame($users, User::count());
        $this->assertGreaterThan(0, DB::table('permissions')->count());
        $this->assertSame(1, DB::table('app_settings')->count());
        $this->assertSame([], Storage::disk('quotations')->allFiles(), 'Los PDF de cotizaciones borradas no deben quedar.');
        // Lo único que queda en Auditoría es el registro de esta operación.
        $this->assertSame(['system.data_reset'], DB::table('audit_logs')->pluck('event')->all());
    }

    public function test_it_can_keep_the_audit_trail(): void
    {
        $this->populate();
        DB::table('audit_logs')->insert(['event' => 'login', 'created_at' => now()]);
        $before = DB::table('audit_logs')->count();

        $this->artisan('data:reset', ['--confirm' => DB::connection()->getDatabaseName(), '--keep-audit' => true])->assertSuccessful();

        $this->assertSame($before + 1, DB::table('audit_logs')->count());
    }

    public function test_a_wrong_confirmation_deletes_nothing(): void
    {
        $this->populate();

        $this->artisan('data:reset', ['--confirm' => 'otra_base'])->assertFailed();

        $this->assertSame(1, SalesDocument::count());
    }

    public function test_without_a_terminal_and_without_confirmation_it_refuses(): void
    {
        $this->populate();

        $this->artisan('data:reset', ['--no-interaction' => true])->assertFailed();

        $this->assertSame(1, Customer::count());
    }
}
