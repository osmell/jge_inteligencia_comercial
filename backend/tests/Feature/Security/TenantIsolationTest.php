<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditInvoice;
use App\Models\CreditMovement;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\Digest;
use App\Models\ImportBatch;
use App\Models\InsightAlert;
use App\Models\InsightRun;
use App\Models\Operator;
use App\Models\PaymentPromise;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Seller;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WhatsappAttachment;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Intento de acceso a datos AJENOS (IDOR): alguien con TODOS los permisos de su rol, pero limitado a la compañía A, prueba cada ruta con
 * identificadores de registros de la compañía B; y un vendedor prueba los de otro vendedor de su misma compañía. Nada puede responder 2xx
 * ni 5xx, y ninguna lista puede contener el texto marcador de lo ajeno.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** Texto marcador de lo que pertenece a la compañía B (nadie de la A debe verlo). */
    private const SECRET = 'ZZ-EMPRESA-B';

    /** Texto marcador de lo que pertenece al vendedor Beto (Vera, de la misma compañía, no debe verlo). */
    private const SELLER_SECRET = 'ZZ-VENDEDOR-BETO';

    private Company $a;

    private Company $b;

    private User $manager;

    private User $vera;

    private User $beto;

    /** @var array<string, int> */
    private array $b_ids = [];

    /** @var array<string, int> */
    private array $beto_ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('conversations');
        Storage::fake('quotations');
        $this->seed(RolePermissionSeeder::class);
        $all = Permission::pluck('id')->all();
        foreach ([Role::GERENTE_GENERAL, Role::VENDEDOR, Role::SUPERVISOR_VENTAS] as $slug) {
            Role::where('slug', $slug)->firstOrFail()->permissions()->sync($all);
        }

        $this->a = Company::factory()->create(['code' => 'A', 'name' => 'Alfa']);
        $this->b = Company::factory()->create(['code' => 'B', 'name' => 'Beta '.self::SECRET]);
        $branchA = Branch::factory()->create(['company_id' => $this->a->id]);
        $branchB = Branch::factory()->create(['company_id' => $this->b->id, 'name' => 'Sucursal '.self::SECRET]);

        $this->manager = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $this->vera = User::factory()->role(Role::VENDEDOR)->create();
        $this->beto = User::factory()->role(Role::VENDEDOR)->create();
        foreach ([$this->manager, $this->vera, $this->beto] as $u) {
            $u->companies()->attach($this->a->id);
        }

        // Compañía A: dos vendedores, cada uno con su cliente.
        $sVera = Seller::create(['company_id' => $this->a->id, 'name' => 'Vera', 'normalized_name' => 'vera', 'user_id' => $this->vera->id, 'branch_id' => $branchA->id]);
        $sBeto = Seller::create(['company_id' => $this->a->id, 'name' => 'Beto', 'normalized_name' => 'beto', 'user_id' => $this->beto->id, 'branch_id' => $branchA->id]);
        $cVera = Customer::create(['company_id' => $this->a->id, 'code' => 'CV', 'name' => 'Cliente Vera', 'source' => 'sales', 'seller_id' => $sVera->id, 'whatsapp' => '+503 7000-0001']);
        $cBeto = Customer::create(['company_id' => $this->a->id, 'code' => 'CB', 'name' => 'Cliente Beto '.self::SELLER_SECRET, 'source' => 'sales', 'seller_id' => $sBeto->id, 'whatsapp' => '+503 7000-0002']);
        $this->beto_ids = $this->records($this->a, $branchA, $cBeto, $sBeto, $this->beto);
        $this->records($this->a, $branchA, $cVera, $sVera, $this->vera);

        // Compañía B: todo marcado con el texto secreto.
        $outsider = User::factory()->role(Role::GERENTE_GENERAL)->create(['name' => 'Ajeno '.self::SECRET]);
        $outsider->companies()->attach($this->b->id);
        $sB = Seller::create(['company_id' => $this->b->id, 'name' => 'Vendedor '.self::SECRET, 'normalized_name' => 'vendedor secreto', 'branch_id' => $branchB->id]);
        $cB = Customer::create(['company_id' => $this->b->id, 'code' => 'CX', 'name' => 'Cliente '.self::SECRET, 'source' => 'sales', 'seller_id' => $sB->id, 'whatsapp' => '+503 7000-0099']);
        $this->b_ids = $this->records($this->b, $branchB, $cB, $sB, $outsider);
        $this->b_ids['branch'] = $branchB->id;
        $this->b_ids['company'] = $this->b->id;
        $this->b_ids['user'] = $outsider->id;
        $this->b_ids['seller'] = $sB->id;
        $this->b_ids['operator'] = Operator::create(['company_id' => $this->b->id, 'name' => 'Caja '.self::SECRET, 'normalized_name' => 'caja secreta'])->id;
        $this->b_ids['batch'] = ImportBatch::factory()->create(['user_id' => $outsider->id, 'original_name' => self::SECRET.'.xlsx'])->id;
        $this->b_ids['notification'] = UserNotification::create(['user_id' => $outsider->id, 'company_id' => $this->b->id, 'type' => 'system', 'severity' => 'info', 'title' => 'Aviso '.self::SECRET, 'dedupe_key' => 'k1', 'status' => 'new', 'created_at' => now()])->id;
    }

    /**
     * Registros de cada tipo para un cliente y su vendedor.
     *
     * @return array<string, int>
     */
    private function records(Company $company, Branch $branch, Customer $customer, Seller $seller, User $owner): array
    {
        $operator = Operator::create(['company_id' => $company->id, 'name' => 'Caja '.$company->code, 'normalized_name' => 'caja '.strtolower($company->code).$customer->id]);
        $invoice = CreditInvoice::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'series' => 'S', 'number' => (string) $customer->id, 'issued_on' => '2026-10-01', 'customer_code' => $customer->code, 'customer_name' => $customer->name,
            'seller_id' => $seller->id, 'operator_id' => $operator->id, 'subtotal' => 50, 'discount_total' => 0, 'tax_total' => 0, 'total' => 50, 'content_hash' => 'h'.$customer->id,
        ]);
        $movement = CreditMovement::create(['company_id' => $company->id, 'credit_invoice_id' => $invoice->id, 'type' => 'payment', 'amount' => -10, 'occurred_on' => '2026-10-02', 'created_at' => now()]);
        $promise = PaymentPromise::create(['company_id' => $company->id, 'customer_code' => $customer->code, 'customer_name' => $customer->name, 'made_on' => '2026-10-02', 'promised_on' => '2026-10-20', 'amount' => 20, 'user_id' => $owner->id]);
        $quotation = Quotation::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'user_id' => $owner->id, 'seller_id' => $seller->id, 'sequence' => $customer->id, 'number' => 'COT-'.str_pad((string) $customer->id, 6, '0', STR_PAD_LEFT), 'status' => 'sent', 'amount' => 100, 'valid_until' => '2027-01-01', 'description' => 'Cotización '.$customer->name]);
        $interaction = CustomerInteraction::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'user_id' => $owner->id, 'channel' => 'call', 'direction' => 'outbound', 'outcome' => 'spoke', 'occurred_at' => now()->subDay(), 'response' => 'Nota '.$customer->name, 'next_action' => 'Llamar', 'next_action_at' => now()->addDay()]);
        $conversation = WhatsappConversation::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'wa_number' => '5037000'.str_pad((string) $customer->id, 4, '0', STR_PAD_LEFT), 'profile_name' => $customer->name, 'created_at' => now(), 'last_message_at' => now(), 'last_inbound_at' => now()]);
        $message = WhatsappMessage::create(['conversation_id' => $conversation->id, 'customer_id' => $customer->id, 'body' => 'Mensaje '.$customer->name, 'kind' => 'inbound', 'direction' => 'in', 'message_type' => 'text', 'status' => 'received', 'wa_message_id' => 'wamid.'.$customer->id, 'created_at' => now()]);
        $attachment = WhatsappAttachment::create(['message_id' => $message->id, 'kind' => 'image', 'path' => $company->id.'/foto.jpg', 'original_name' => 'foto.jpg', 'mime' => 'image/jpeg', 'size' => 8, 'created_at' => now()]);
        Storage::disk('conversations')->put($company->id.'/foto.jpg', 'x');
        $unit = Unit::first() ?? Unit::create(['code' => 'unidad', 'name' => 'Unidad']);
        $product = Product::create(['company_id' => $company->id, 'code' => 'P'.$customer->id, 'name' => 'Producto '.$customer->name, 'base_unit_id' => $unit->id]);
        $run = InsightRun::firstOrCreate(['company_id' => $company->id, 'analysis' => 'inactivity'], ['status' => 'ok', 'parameters' => [], 'computed_at' => now()]);
        $alert = InsightAlert::create(['company_id' => $company->id, 'insight_run_id' => $run->id, 'customer_code' => $customer->code, 'customer_name' => $customer->name, 'type' => 'inactivity_risk', 'severity' => 'high', 'dedupe_key' => 'd'.$customer->id, 'facts' => [], 'explanation' => 'Riesgo '.$customer->name, 'status' => 'open', 'first_detected_at' => now(), 'last_detected_at' => now()]);
        $digest = Digest::firstOrCreate(['company_id' => $company->id, 'kind' => 'daily', 'period_key' => '2026-10-20'], ['period_from' => '2026-10-20', 'period_to' => '2026-10-20', 'title' => 'Resumen '.$company->name, 'data' => [], 'generated_at' => now()]);

        return [
            'customer' => $customer->id, 'invoice' => $invoice->id, 'movement' => $movement->id, 'promise' => $promise->id, 'quotation' => $quotation->id, 'interaction' => $interaction->id,
            'conversation' => $conversation->id, 'message' => $message->id, 'attachment' => $attachment->id, 'product' => $product->id, 'alert' => $alert->id, 'digest' => $digest->id,
        ];
    }

    /** @return array<string, array{string, string, array<string, mixed>}> */
    private function routesFor(array $ids): array
    {
        $q = $ids['quotation'];
        $c = $ids['conversation'];
        $i = $ids['interaction'];

        return [
            'cotización: ver' => ['GET', "quotations/$q", []],
            'cotización: editar' => ['PUT', "quotations/$q", ['amount' => '1.00']],
            'cotización: estado' => ['PUT', "quotations/$q/status", ['status' => 'accepted']],
            'cotización: adjunto' => ['GET', "quotations/$q/attachment", []],
            'cotización: subir adjunto' => ['POST', "quotations/$q/attachment", ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]],
            'cotización: facturas vinculables' => ['GET', "quotations/$q/documents", []],
            'cotización: vincular' => ['PUT', "quotations/$q/link", ['document_id' => 1]],
            'factura de crédito: ver' => ['GET', 'credit/invoices/'.$ids['invoice'], []],
            'crédito: revertir movimiento' => ['POST', 'credit/movements/'.$ids['movement'].'/reverse', ['note' => 'x']],
            'crédito: cancelar promesa' => ['POST', 'credit/promises/'.$ids['promise'].'/cancel', ['reason' => 'prueba de acceso']],
            'contacto: corregir' => ['PUT', "customers/interactions/$i", ['channel' => 'call', 'direction' => 'outbound', 'outcome' => 'spoke', 'occurred_at' => now()->toIso8601String()]],
            'contacto: completar acción' => ['PUT', "customers/interactions/$i/complete", ['done' => true]],
            'cliente: WhatsApp' => ['GET', 'customers/'.$ids['customer'].'/whatsapp', []],
            'cliente: consentimiento' => ['POST', 'customers/'.$ids['customer'].'/whatsapp/consent', ['status' => 'granted', 'source' => 'prueba']],
            'cliente: enviar plantilla' => ['POST', 'customers/'.$ids['customer'].'/whatsapp/send', ['template_id' => 1]],
            'conversación: mensajes' => ['GET', "conversations/$c/messages", []],
            'conversación: escribir' => ['POST', "conversations/$c/messages", ['body' => 'hola']],
            'conversación: notas' => ['GET', "conversations/$c/notes", []],
            'conversación: agregar nota' => ['POST', "conversations/$c/notes", ['body' => 'nota']],
            'conversación: leída' => ['POST', "conversations/$c/read", []],
            'conversación: asignar' => ['POST', "conversations/$c/assign", ['customer_id' => 1]],
            'conversación: candidatos' => ['GET', "conversations/$c/candidates", []],
            'conversación: etiquetas' => ['PUT', "conversations/$c/labels", ['labels' => []]],
            'conversación: cotizaciones' => ['GET', "conversations/$c/quotations", []],
            'conversación: enviar cotización' => ['POST', "conversations/$c/quotation", ['quotation_id' => $q]],
            'conversación: plantilla' => ['POST', "conversations/$c/template", ['template_id' => 1]],
            'conversación: archivo adjunto' => ['GET', 'conversations/attachments/'.$ids['attachment'], []],
            'conversación: reintentar envío' => ['POST', 'conversations/messages/'.$ids['message'].'/retry', []],
            'inventario: unidades del producto' => ['GET', 'inventory/products/'.$ids['product'].'/units', []],
            'inventario: cambiar unidades' => ['PUT', 'inventory/products/'.$ids['product'].'/units', ['base_unit_id' => 1, 'equivalences' => []]],
            'recomendación: cerrar' => ['POST', 'insights/alerts/'.$ids['alert'].'/close', ['reason' => 'contacted']],
            'recomendación: crear acción' => ['POST', 'insights/alerts/'.$ids['alert'].'/follow-up', ['next_action' => 'Llamar', 'next_action_at' => now()->addDay()->toIso8601String()]],
            'resumen: ver' => ['GET', 'digests/'.$ids['digest'], []],
        ];
    }

    private function assertDenied(string $label, string $method, string $uri, array $body, User $user, string $secret = self::SECRET): void
    {
        $response = $this->actingAs($user, 'sanctum')->json($method, "/api/v1/$uri", $body);
        if (array_filter($body, fn ($v) => $v instanceof UploadedFile) !== []) {
            $response = $this->actingAs($user, 'sanctum')->post("/api/v1/$uri", $body, ['Accept' => 'application/json']);
        }
        $status = $response->getStatusCode();

        $this->assertContains($status, [403, 404, 422], "$label: $method /$uri respondió $status (se esperaba 403, 404 o 422, nunca éxito ni error de servidor).");
        $this->assertStringNotContainsString($secret, (string) $response->getContent(), "$label: la respuesta contiene datos ajenos.");
    }

    public function test_a_user_of_company_a_cannot_touch_records_of_company_b(): void
    {
        foreach ($this->routesFor($this->b_ids) as $label => [$method, $uri, $body]) {
            $this->assertDenied($label, $method, $uri, $body, $this->manager);
        }
    }

    public function test_a_seller_cannot_touch_the_records_of_another_seller_of_the_same_company(): void
    {
        foreach ($this->routesFor($this->beto_ids) as $label => [$method, $uri, $body]) {
            if (str_starts_with($label, 'inventario') || str_starts_with($label, 'resumen')) {
                continue;      // el catálogo de productos y los resúmenes son de la compañía (los gobierna el permiso), no de un vendedor
            }
            $this->assertDenied($label, $method, $uri, $body, $this->vera, self::SELLER_SECRET);
        }
    }

    public function test_administration_records_of_another_company_are_not_reachable(): void
    {
        $admin = [
            'usuario: ver' => ['GET', 'admin/users/'.$this->b_ids['user'], []],
            'usuario: editar' => ['PUT', 'admin/users/'.$this->b_ids['user'], ['username' => 'otro.usuario', 'name' => 'X', 'role_id' => Role::where('slug', Role::VENDEDOR)->value('id'), 'is_active' => true, 'company_ids' => [$this->a->id], 'branch_ids' => []]],
            'sucursal: ver' => ['GET', 'admin/branches/'.$this->b_ids['branch'], []],
            'sucursal: editar' => ['PUT', 'admin/branches/'.$this->b_ids['branch'], ['name' => 'X']],
            'sucursal: mover' => ['GET', 'admin/branches/'.$this->b_ids['branch'].'/relocation', []],
            'compañía: ver' => ['GET', 'admin/companies/'.$this->b_ids['company'], []],
            'compañía: editar' => ['PUT', 'admin/companies/'.$this->b_ids['company'], ['name' => 'X']],
            'vendedor: editar' => ['PUT', 'customers/sellers/'.$this->b_ids['seller'], ['name' => 'X']],
            'facturador: editar' => ['PUT', 'customers/operators/'.$this->b_ids['operator'], ['name' => 'X']],
            'importación: ver' => ['GET', 'admin/imports/'.$this->b_ids['batch'], []],
            'importación: errores' => ['GET', 'admin/imports/'.$this->b_ids['batch'].'/errors', []],
            'importación: errores CSV' => ['GET', 'admin/imports/'.$this->b_ids['batch'].'/errors.csv', []],
            'importación: confirmar' => ['POST', 'admin/imports/'.$this->b_ids['batch'].'/confirm', []],
            'importación: descartar' => ['POST', 'admin/imports/'.$this->b_ids['batch'].'/discard', []],
            'aviso ajeno: marcar visto' => ['POST', 'notifications/'.$this->b_ids['notification'].'/seen', []],
            'aviso ajeno: descartar' => ['POST', 'notifications/'.$this->b_ids['notification'].'/dismiss', []],
        ];

        foreach ($admin as $label => [$method, $uri, $body]) {
            $this->assertDenied($label, $method, $uri, $body, $this->manager);
        }
    }

    public function test_lists_never_contain_data_of_another_company(): void
    {
        $lists = ['quotations', 'customers', 'credit/invoices', 'credit/aging', 'credit/promises', 'conversations', 'admin/users', 'admin/branches', 'admin/companies', 'admin/imports', 'companies', 'branches',
            'insights/alerts?company_id='.$this->b->id, 'digests', 'notifications', 'customers/agenda', 'inventory/stock', 'sales/products', 'audit-logs'];

        foreach ($lists as $uri) {
            $response = $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/$uri");
            $this->assertLessThan(500, $response->getStatusCode(), "GET /$uri respondió ".$response->getStatusCode());
            $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent(), "GET /$uri devuelve datos de otra compañía.");
        }
    }

    public function test_asking_for_another_company_through_a_filter_returns_nothing_of_it(): void
    {
        $id = $this->b->id;
        $queries = ["sales/summary?company_id=$id&from=2026-01-01&to=2026-12-31", "sales/breakdown?company_id=$id&dimension=customer&from=2026-01-01&to=2026-12-31", "credit/aging?company_id=$id",
            "credit/invoices?company_id=$id", "customers?company_id=$id", "quotations?company_id=$id", "inventory/stock?company_id=$id", "inventory/rotation?company_id=$id",
            "insights/alerts?company_id=$id", "credit/promises?company_id=$id&status=all", "sales/goals?company_id=$id&month=2026-10", "customers/agenda?company_id=$id"];

        foreach ($queries as $uri) {
            $response = $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/$uri");
            $this->assertLessThan(500, $response->getStatusCode(), "GET /$uri respondió ".$response->getStatusCode());
            $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent(), "GET /$uri deja ver datos de otra compañía con el filtro company_id.");
        }
    }

    public function test_a_seller_lists_do_not_contain_another_sellers_data(): void
    {
        foreach (['quotations', 'customers', 'conversations', 'credit/invoices', 'customers/agenda', 'insights/alerts?company_id='.$this->a->id] as $uri) {
            $response = $this->actingAs($this->vera, 'sanctum')->getJson("/api/v1/$uri");
            $this->assertLessThan(500, $response->getStatusCode(), "GET /$uri respondió ".$response->getStatusCode());
            $this->assertStringNotContainsString(self::SELLER_SECRET, (string) $response->getContent(), "GET /$uri le muestra a un vendedor datos de otro vendedor.");
        }
    }
}
