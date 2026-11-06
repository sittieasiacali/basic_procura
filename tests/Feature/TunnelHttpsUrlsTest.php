<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TunnelHttpsUrlsTest extends TestCase
{
    public function test_local_tunnel_keeps_urls_secure_with_a_stale_http_app_url(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        $this->app->getProvider(AppServiceProvider::class)->boot();

        foreach (['127.0.0.1', '::1'] as $proxy) {
            $this->withServerVariables(['REMOTE_ADDR' => $proxy])
                ->withHeader('X-Forwarded-Proto', 'https')
                ->get('http://current-tunnel.example/login')->assertOk()
                ->assertSee('action="https://current-tunnel.example/login"', false)
                ->assertDontSee('http://current-tunnel.example', false);

            $this->get('http://current-tunnel.example/dashboard')
                ->assertRedirect('https://current-tunnel.example/login');
        }
    }

    public function test_preview_urls_use_the_current_tunnel_host(): void
    {
        config(['app.url' => 'http://old-tunnel.example']);
        Route::get('/tunnel-url-test', fn () => response()->json([
            'preview_url' => route('purchase-requests.show', 140),
        ]));

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'untrusted.example',
            ])->get('http://current-tunnel.example/tunnel-url-test')
            ->assertOk()->assertJsonPath('preview_url', 'https://current-tunnel.example/purchase-requests/140');
    }

    public function test_forwarded_scheme_from_a_direct_remote_client_is_ignored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('http://procura.example/login')->assertOk()
            ->assertSee('action="http://procura.example/login"', false);
    }
}
