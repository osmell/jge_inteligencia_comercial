<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\WebhookTransport;
use App\Contracts\WhatsappMediaSource;
use App\Contracts\WhatsappMessaging;
use App\Integrations\GraphApiWhatsapp;
use App\Integrations\HttpWebhookTransport;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeWhatsappMessaging;
use Tests\TestCase;

/** El puerto WhatsappMessaging se puede sustituir sin tocar el servicio ni usar la red (principio de inversión de dependencias). */
class WhatsappPortTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_connection_test_works_through_a_replaced_gateway_without_http(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->app->instance(WhatsappMessaging::class, new FakeWhatsappMessaging);
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->role(Role::ADMINISTRADOR)->create();

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/whatsapp/settings', ['enabled' => true, 'phone_number_id' => '1234567890', 'api_version' => 'v21.0', 'access_token' => 'tok', 'app_secret' => 'sec', 'verify_token' => 'vt-9x2'])->assertOk();
        $this->postJson('/api/v1/whatsapp/test')->assertOk()->assertJsonPath('data.ok', true)->assertJsonFragment(['message' => 'Conexión correcta con JGE (doble) (+503 0000-0000).']);

        Http::assertNothingSent();
    }

    public function test_the_default_binding_is_the_graph_api_implementation(): void
    {
        $this->assertInstanceOf(GraphApiWhatsapp::class, $this->app->make(WhatsappMessaging::class));
        $this->assertInstanceOf(GraphApiWhatsapp::class, $this->app->make(WhatsappMediaSource::class));
        $this->assertInstanceOf(HttpWebhookTransport::class, $this->app->make(WebhookTransport::class));
    }
}
