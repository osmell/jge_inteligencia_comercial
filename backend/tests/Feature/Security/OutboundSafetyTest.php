<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Integrations\GraphApiWhatsapp;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsappAttachment;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSetting;
use App\Services\WhatsappMedia;
use App\Support\PublicUrl;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Llamadas salientes del servidor (SSRF): el servidor nunca debe poder usarse para alcanzar la red interna ni para filtrar su token. */
class OutboundSafetyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function unsafeUrls(): array
    {
        return [
            'metadatos de la nube' => ['http://169.254.169.254/latest/meta-data/'],
            'localhost' => ['http://localhost:8000/admin'],
            'loopback' => ['http://127.0.0.1/'],
            'red privada 10' => ['http://10.0.0.5/hook'],
            'red privada 192.168' => ['https://192.168.1.10/hook'],
            'red privada 172.16' => ['https://172.16.0.9/hook'],
            'IPv6 loopback' => ['https://[::1]/hook'],
            'dominio interno' => ['https://base-de-datos.internal/hook'],
            'esquema distinto' => ['ftp://8.8.8.8/hook'],
            'archivo local' => ['file:///etc/passwd'],
            'credenciales en la dirección' => ['https://usuario:clave@8.8.8.8/hook'],
            'sin host' => ['https:///hook'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_internal_and_malformed_addresses_are_unsafe(string $url): void
    {
        $this->assertFalse(PublicUrl::isSafe($url, false), $url);
        $this->assertFalse(PublicUrl::isSafe($url, true), $url);
    }

    public function test_a_public_https_address_is_safe_and_production_requires_https(): void
    {
        $this->assertTrue(PublicUrl::isSafe('https://8.8.8.8/hook', true));
        $this->assertTrue(PublicUrl::isSafe('http://8.8.8.8/hook', false));
        $this->assertFalse(PublicUrl::isSafe('http://8.8.8.8/hook', true), 'En producción se exige HTTPS.');
    }

    public function test_the_webhook_destination_is_validated_in_production_but_local_is_allowed_to_develop(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs(User::factory()->role(Role::ADMINISTRADOR)->create(), 'sanctum');
        $body = fn (string $url) => ['enabled' => false, 'url' => $url, 'secret' => str_repeat('s', 20), 'events' => []];

        config(['security.allow_private_urls' => false]);
        $this->putJson('/api/v1/webhook/settings', $body('http://169.254.169.254/latest'))->assertStatus(422)->assertJsonValidationErrors('url');
        $this->putJson('/api/v1/webhook/settings', $body('https://10.0.0.5/hook'))->assertStatus(422);
        $this->putJson('/api/v1/webhook/settings', $body('https://8.8.8.8/hook'))->assertOk();

        config(['security.allow_private_urls' => true]);
        $this->putJson('/api/v1/webhook/settings', $body('http://localhost:9000/hook'))->assertOk();
    }

    #[DataProvider('metaUrls')]
    public function test_only_meta_https_domains_are_media_addresses(string $url, bool $expected): void
    {
        $this->assertSame($expected, GraphApiWhatsapp::isMetaMediaUrl($url), $url);
    }

    /** @return array<string, array{string, bool}> */
    public static function metaUrls(): array
    {
        return [
            'lookaside' => ['https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', true],
            'cdn' => ['https://scontent.xx.fbcdn.net/v/t1/file.jpg', true],
            'whatsapp.net' => ['https://mmg.whatsapp.net/d/f/file', true],
            'dominio ajeno' => ['https://evil.example/file', false],
            'sufijo engañoso' => ['https://fbsbx.com.evil.example/file', false],
            'parecido' => ['https://notfbsbx.com/file', false],
            'sin cifrado' => ['http://lookaside.fbsbx.com/file', false],
            'dirección interna' => ['https://169.254.169.254/latest', false],
            'con credenciales' => ['https://user@lookaside.fbsbx.com/file', false],
        ];
    }

    public function test_a_media_address_that_is_not_meta_is_refused_without_sending_the_token(): void
    {
        Storage::fake('conversations');
        Http::fake([
            'graph.facebook.com/*' => Http::response(['url' => 'https://evil.example/steal', 'mime_type' => 'image/png', 'file_size' => 10]),
            'evil.example/*' => Http::response('x'),
        ]);
        WhatsappSetting::current()->forceFill(['enabled' => true, 'phone_number_id' => '1', 'access_token' => 'TOKEN-SECRETO', 'api_version' => 'v21.0'])->save();
        $conversation = WhatsappConversation::create(['company_id' => Company::factory()->create()->id, 'wa_number' => '50370000001', 'created_at' => now()]);
        $message = WhatsappMessage::create(['conversation_id' => $conversation->id, 'body' => '', 'kind' => 'inbound', 'direction' => 'in', 'message_type' => 'image', 'status' => 'received', 'created_at' => now()]);
        $attachment = WhatsappAttachment::create(['message_id' => $message->id, 'kind' => 'image', 'status' => WhatsappAttachment::PENDING, 'media_id' => 'M1', 'created_at' => now()]);

        app(WhatsappMedia::class)->download($attachment);

        $this->assertSame(WhatsappAttachment::FAILED, $attachment->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example'));
    }
}
