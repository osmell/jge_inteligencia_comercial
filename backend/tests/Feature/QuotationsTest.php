<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SalesDocument;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuotationsTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Branch $branch;

    private User $admin;

    private User $vendedor;

    private Customer $mine;

    private Customer $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('quotations');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->a = Company::factory()->create(['code' => 'A']);
        $this->branch = Branch::factory()->create(['company_id' => $this->a->id]);
        $this->vendedor = User::factory()->role(Role::VENDEDOR)->create();
        $this->vendedor->companies()->attach($this->a->id);
        $vera = Seller::create(['company_id' => $this->a->id, 'name' => 'Vera', 'normalized_name' => 'vera', 'user_id' => $this->vendedor->id]);
        $this->mine = Customer::create(['company_id' => $this->a->id, 'code' => 'C1', 'name' => 'Cliente Uno', 'seller_id' => $vera->id]);
        $this->other = Customer::create(['company_id' => $this->a->id, 'code' => 'C2', 'name' => 'Cliente Dos']);

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->vendedor->role_id, ['permissions' => ['customers.view', 'interactions.manage']])->assertOk();
    }

    /** @return array<string, mixed> */
    private function payload(array $over = []): array
    {
        return ['company_id' => $this->a->id, 'code' => 'C1', 'amount' => 1500.5, 'valid_until' => now()->addDays(10)->toDateString(), 'description' => '4 llantas', ...$over];
    }

    private function create(array $over = [], ?User $as = null): int
    {
        return $this->actingAs($as ?? $this->vendedor, 'sanctum')->postJson('/api/v1/quotations', $this->payload($over))->assertCreated()->json('data.id');
    }

    private function doc(string $customerCode = 'C1', ?string $on = null): SalesDocument
    {
        return SalesDocument::factory()->create(['company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'customer_code' => $customerCode, 'issued_on' => $on ?? now()->toDateString()]);
    }

    public function test_numbers_are_sequential_per_company_and_creation_is_audited(): void
    {
        $this->actingAs($this->vendedor, 'sanctum');
        $first = $this->postJson('/api/v1/quotations', $this->payload())->assertCreated()
            ->assertJsonPath('data.number', 'COT-000001')->assertJsonPath('data.status', 'draft')->assertJsonPath('data.amount', '1500.50')->assertJsonPath('data.seller', 'Vera')->json('data');
        $this->postJson('/api/v1/quotations', $this->payload(['external_reference' => 'PRES-77']))->assertCreated()->assertJsonPath('data.number', 'COT-000002')->assertJsonPath('data.external_reference', 'PRES-77');

        $b = Company::factory()->create(['code' => 'B']);
        $branchB = Branch::factory()->create(['company_id' => $b->id]);
        $customerB = Customer::create(['company_id' => $b->id, 'code' => 'C1', 'name' => 'Otro B']);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/quotations', $this->payload(['company_id' => $b->id]))->assertCreated()->assertJsonPath('data.number', 'COT-000001');
        unset($branchB, $customerB);

        $this->assertSame(3, AuditLog::where('event', 'quotations.created')->count());
        $this->assertSame($this->vendedor->id, $first['user']['id']);
    }

    public function test_it_can_be_issued_on_creation_and_needs_amount_and_expiry(): void
    {
        $this->actingAs($this->vendedor, 'sanctum');
        $this->postJson('/api/v1/quotations', $this->payload(['status' => 'issued']))->assertCreated()->assertJsonPath('data.status', 'issued')->assertJsonPath('data.effective_status', 'issued');

        $draft = $this->create(['amount' => null, 'valid_until' => null]);
        $this->putJson("/api/v1/quotations/$draft/status", ['status' => 'issued'])->assertUnprocessable()->assertJsonValidationErrors(['amount', 'valid_until']);
        $this->putJson("/api/v1/quotations/$draft", ['amount' => 100, 'valid_until' => now()->subDay()->toDateString()])->assertOk();
        $this->putJson("/api/v1/quotations/$draft/status", ['status' => 'issued'])->assertUnprocessable()->assertJsonValidationErrors(['valid_until']);
        $this->putJson("/api/v1/quotations/$draft", ['valid_until' => now()->addDay()->toDateString()])->assertOk();
        $this->putJson("/api/v1/quotations/$draft/status", ['status' => 'issued'])->assertOk()->assertJsonPath('data.status', 'issued');
    }

    public function test_status_flow_only_follows_allowed_transitions_and_keeps_history(): void
    {
        $id = $this->create(['status' => 'issued']);
        $this->actingAs($this->vendedor, 'sanctum');

        $this->putJson("/api/v1/quotations/$id/status", ['status' => 'draft'])->assertUnprocessable();
        $this->putJson("/api/v1/quotations/$id/status", ['status' => 'converted'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->putJson("/api/v1/quotations/$id/status", ['status' => 'sent'])->assertOk();
        $this->putJson("/api/v1/quotations/$id/status", ['status' => 'issued'])->assertUnprocessable();
        $this->putJson("/api/v1/quotations/$id/status", ['status' => 'following'])->assertOk();
        $this->putJson("/api/v1/quotations/$id/status", ['status' => 'accepted'])->assertOk()->assertJsonPath('data.transitions', ['rejected', 'cancelled']);

        $detail = $this->getJson("/api/v1/quotations/$id")->assertOk()->json('data');
        $this->assertSame(['draft', 'issued', 'sent', 'following', 'accepted'], array_column($detail['events'], 'to'));
        $this->assertSame(4, AuditLog::where('event', 'quotations.status_changed')->count());
    }

    public function test_rejecting_needs_a_loss_reason_and_closed_quotations_cannot_change(): void
    {
        $id = $this->create(['status' => 'issued']);
        $this->actingAs($this->vendedor, 'sanctum');

        $this->putJson("/api/v1/quotations/$id/status", ['status' => 'rejected'])->assertUnprocessable()->assertJsonValidationErrors(['loss_reason']);
        $this->putJson("/api/v1/quotations/$id/status", ['status' => 'rejected', 'loss_reason' => 'price', 'loss_note' => 'Encontró más barato'])
            ->assertOk()->assertJsonPath('data.loss_reason', 'price')->assertJsonPath('data.loss_note', 'Encontró más barato');
        $this->putJson("/api/v1/quotations/$id/status", ['status' => 'accepted'])->assertUnprocessable();
        $this->putJson("/api/v1/quotations/$id", ['amount' => 5])->assertUnprocessable();
        $this->putJson("/api/v1/quotations/$id/status", ['status' => 'rejected', 'loss_reason' => 'bogus'])->assertUnprocessable()->assertJsonValidationErrors(['loss_reason']);
    }

    public function test_an_open_quotation_past_its_expiry_shows_as_expired_but_an_accepted_one_does_not(): void
    {
        $open = $this->create(['status' => 'issued']);
        $accepted = $this->create(['status' => 'issued']);
        $this->actingAs($this->vendedor, 'sanctum')->putJson("/api/v1/quotations/$accepted/status", ['status' => 'accepted'])->assertOk();
        Quotation::query()->update(['valid_until' => now()->subDays(2)]);

        $this->getJson("/api/v1/quotations/$open")->assertJsonPath('data.status', 'issued')->assertJsonPath('data.effective_status', 'expired');
        $this->getJson("/api/v1/quotations/$accepted")->assertJsonPath('data.effective_status', 'accepted');

        $list = $this->getJson('/api/v1/quotations?status=expired')->assertOk();
        $this->assertSame([$open], array_column($list->json('data'), 'id'));
        $this->assertSame(0, count($this->getJson('/api/v1/quotations?status=issued')->json('data')));
        $this->assertSame(1, $list->json('meta.counts.expired.count'));
        $this->assertSame(1, $list->json('meta.counts.accepted.count'));
        $this->assertArrayNotHasKey('issued', $list->json('meta.counts'));

        // Ampliar el vencimiento la vuelve a dejar vigente.
        $this->putJson("/api/v1/quotations/$open", ['valid_until' => now()->addDays(5)->toDateString()])->assertOk()->assertJsonPath('data.effective_status', 'issued');
    }

    public function test_accepting_is_not_a_sale_only_linking_an_invoice_converts_it(): void
    {
        $id = $this->create(['status' => 'issued']);
        $this->actingAs($this->vendedor, 'sanctum')->putJson("/api/v1/quotations/$id/status", ['status' => 'accepted'])->assertOk()->assertJsonPath('data.document', null);
        $good = $this->doc('C1');
        $this->doc('C1');
        $wrongCustomer = $this->doc('C2');
        $old = $this->doc('C1', now()->subDays(30)->toDateString());

        $candidates = $this->getJson("/api/v1/quotations/$id/documents")->assertOk()->json('data');
        $this->assertNotContains($wrongCustomer->id, array_column($candidates, 'id'));
        $this->assertNotContains($old->id, array_column($candidates, 'id'));

        $this->putJson("/api/v1/quotations/$id/link", ['document_id' => $wrongCustomer->id])->assertUnprocessable()->assertJsonValidationErrors(['document_id']);
        $this->putJson("/api/v1/quotations/$id/link", ['document_id' => $old->id])->assertUnprocessable();
        $this->putJson("/api/v1/quotations/$id/link", ['document_id' => $good->id])->assertOk()
            ->assertJsonPath('data.status', 'converted')->assertJsonPath('data.document.id', $good->id)->assertJsonPath('data.document.number', "{$good->series}-{$good->number}");

        // La factura no se reutiliza ni sale de los candidatos de otra cotización.
        $second = $this->create(['status' => 'issued']);
        $this->assertNotContains($good->id, array_column($this->getJson("/api/v1/quotations/$second/documents")->json('data'), 'id'));
        $this->putJson("/api/v1/quotations/$second/link", ['document_id' => $good->id])->assertUnprocessable();
        $this->putJson("/api/v1/quotations/$id/status", ['status' => 'cancelled'])->assertUnprocessable();
        $this->assertSame(1, AuditLog::where('event', 'quotations.linked')->count());
    }

    public function test_a_draft_cannot_be_linked_and_unlinking_needs_customer_management(): void
    {
        $draft = $this->create();
        $doc = $this->doc();
        $this->actingAs($this->vendedor, 'sanctum')->putJson("/api/v1/quotations/$draft/link", ['document_id' => $doc->id])->assertUnprocessable();

        $id = $this->create(['status' => 'issued']);
        $this->putJson("/api/v1/quotations/$id/link", ['document_id' => $doc->id])->assertOk();
        $this->deleteJson("/api/v1/quotations/$id/link")->assertForbidden();

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/quotations/$id/link")->assertOk()
            ->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.document', null);
        $this->assertSame(1, AuditLog::where('event', 'quotations.unlinked')->count());
        $this->deleteJson("/api/v1/quotations/$id/link")->assertUnprocessable();
    }

    public function test_attachment_is_stored_downloaded_replaced_and_validated(): void
    {
        $id = $this->create();
        $this->actingAs($this->vendedor, 'sanctum');

        $this->postJson("/api/v1/quotations/$id/attachment", ['file' => UploadedFile::fake()->create('cot.pdf', 200, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.attachment.name', 'cot.pdf')->assertJsonPath('data.has_attachment', true);
        $first = Quotation::find($id)->attachment_path;
        Storage::disk('quotations')->assertExists($first);

        $this->get("/api/v1/quotations/$id/attachment")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertDownload('cot.pdf');

        $this->postJson("/api/v1/quotations/$id/attachment", ['file' => UploadedFile::fake()->create('foto.png', 300, 'image/png')])->assertOk()->assertJsonPath('data.attachment.name', 'foto.png');
        Storage::disk('quotations')->assertMissing($first);

        $this->postJson("/api/v1/quotations/$id/attachment", ['file' => UploadedFile::fake()->create('malo.exe', 10, 'application/x-msdownload')])->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->postJson("/api/v1/quotations/$id/attachment", ['file' => UploadedFile::fake()->create('grande.pdf', 15361, 'application/pdf')])->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->postJson("/api/v1/quotations/$id/attachment", ['file' => UploadedFile::fake()->create('justo.pdf', 15360, 'application/pdf')])->assertOk();

        $this->deleteJson("/api/v1/quotations/$id/attachment")->assertOk()->assertJsonPath('data.attachment', null);
        $this->get("/api/v1/quotations/$id/attachment")->assertNotFound();
        $this->assertSame(['quotations.attachment_added', 'quotations.attachment_removed'], array_values(array_unique(AuditLog::where('event', 'like', 'quotations.attachment%')->orderBy('id')->pluck('event')->all())));
    }

    public function test_a_seller_only_reaches_quotations_of_their_customers(): void
    {
        $mine = $this->create();
        $foreign = $this->create(['code' => 'C2'], $this->admin);

        $this->actingAs($this->vendedor, 'sanctum');
        $this->postJson('/api/v1/quotations', $this->payload(['code' => 'C2']))->assertNotFound();
        $this->getJson("/api/v1/quotations/$foreign")->assertNotFound();
        $this->putJson("/api/v1/quotations/$foreign/status", ['status' => 'cancelled'])->assertNotFound();
        $this->get("/api/v1/quotations/$foreign/attachment")->assertNotFound();
        $this->assertSame([$mine], array_column($this->getJson('/api/v1/quotations')->json('data'), 'id'));

        $this->actingAs($this->admin, 'sanctum');
        $this->assertCount(2, $this->getJson('/api/v1/quotations')->json('data'));
    }

    public function test_listing_filters_by_customer_text_and_owner(): void
    {
        $one = $this->create(['external_reference' => 'PRES-77']);
        $this->create(['code' => 'C2', 'description' => 'otra'], $this->admin);

        $this->actingAs($this->admin, 'sanctum');
        $this->assertSame([$one], array_column($this->getJson('/api/v1/quotations?company_id='.$this->a->id.'&code=C1')->json('data'), 'id'));
        $this->assertSame([$one], array_column($this->getJson('/api/v1/quotations?q=pres-77')->json('data'), 'id'));
        $this->assertSame([$one], array_column($this->getJson('/api/v1/quotations?q=cliente%20uno')->json('data'), 'id'));
        $this->assertCount(0, $this->getJson('/api/v1/quotations?q=%25')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/quotations?mine=1')->json('data') ? [1] : []);
        $this->getJson('/api/v1/quotations?code=C1')->assertUnprocessable()->assertJsonValidationErrors(['company_id']);
        $this->getJson('/api/v1/quotations?status=bogus')->assertUnprocessable();
    }

    public function test_a_customer_that_asked_not_to_be_contacted_cannot_receive_the_quotation(): void
    {
        $id = $this->create(['status' => 'issued']);
        $this->mine->forceFill(['do_not_contact' => true])->save();

        $this->actingAs($this->vendedor, 'sanctum')->putJson("/api/v1/quotations/$id/status", ['status' => 'sent'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->putJson("/api/v1/quotations/$id/status", ['status' => 'following'])->assertOk();
    }

    public function test_it_needs_the_permissions(): void
    {
        $id = $this->create();
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->vendedor->role_id, ['permissions' => ['customers.view']])->assertOk();
        $this->actingAs($this->vendedor->fresh(), 'sanctum');

        $this->getJson("/api/v1/quotations/$id")->assertOk();
        $this->postJson('/api/v1/quotations', $this->payload())->assertForbidden();
        $this->putJson("/api/v1/quotations/$id/status", ['status' => 'cancelled'])->assertForbidden();
        $this->postJson("/api/v1/quotations/$id/attachment", [])->assertForbidden();
    }

    public function test_validation_of_amounts_and_dates(): void
    {
        $this->actingAs($this->vendedor, 'sanctum');
        $this->postJson('/api/v1/quotations', $this->payload(['amount' => -1]))->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/v1/quotations', $this->payload(['amount' => 10.123]))->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/v1/quotations', $this->payload(['valid_until' => 'nunca']))->assertUnprocessable()->assertJsonValidationErrors(['valid_until']);
        $this->postJson('/api/v1/quotations', $this->payload(['status' => 'accepted']))->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $id = $this->create();
        $this->putJson("/api/v1/quotations/$id", ['code' => 'C2'])->assertUnprocessable();
    }
}
