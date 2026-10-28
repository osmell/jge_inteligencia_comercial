<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** `FRONTEND_URL` admite varias direcciones: CORS las acepta todas y los enlaces de los correos usan la primera. */
class FrontendOriginsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('FRONTEND_URL');
        parent::tearDown();
    }

    public function test_several_origins_are_all_allowed_and_links_use_the_first(): void
    {
        putenv('FRONTEND_URL=http://192.168.0.5:5173, http://localhost:5173');
        $_ENV['FRONTEND_URL'] = $_SERVER['FRONTEND_URL'] = 'http://192.168.0.5:5173, http://localhost:5173';

        $cors = require config_path('cors.php');
        $app = require config_path('app.php');

        $this->assertSame(['http://192.168.0.5:5173', 'http://localhost:5173'], $cors['allowed_origins']);
        $this->assertSame('http://192.168.0.5:5173', $app['frontend_url']);
    }

    public function test_a_single_origin_keeps_working(): void
    {
        $_ENV['FRONTEND_URL'] = $_SERVER['FRONTEND_URL'] = 'http://localhost:5173';
        putenv('FRONTEND_URL=http://localhost:5173');

        $this->assertSame(['http://localhost:5173'], (require config_path('cors.php'))['allowed_origins']);
        $this->assertSame('http://localhost:5173', (require config_path('app.php'))['frontend_url']);
    }

    public function test_the_server_answers_cors_for_an_allowed_origin_only(): void
    {
        config(['cors.allowed_origins' => ['http://192.168.0.5:5173', 'http://localhost:5173']]);

        foreach (['http://192.168.0.5:5173', 'http://localhost:5173'] as $origin) {
            $this->withHeader('Origin', $origin)->get('/sanctum/csrf-cookie')->assertHeader('Access-Control-Allow-Origin', $origin);
        }
        $this->withHeader('Origin', 'http://evil.example')->get('/sanctum/csrf-cookie')->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
