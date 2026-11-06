<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

class PublicHttpsUrlsTest extends TestCase
{
    public function test_https_public_url_keeps_forms_assets_and_redirects_secure_on_an_http_origin(): void
    {
        config(['app.url' => 'https://procura.example']);
        $this->app->getProvider(AppServiceProvider::class)->boot();

        $this->get('http://procura.example/login')->assertOk()
            ->assertSee('action="https://procura.example/login"', false)
            ->assertSee('https://procura.example/assets/images/asi-procura-logo-login.png', false)
            ->assertDontSee('http://procura.example', false);

        $this->get('http://procura.example/dashboard')
            ->assertRedirect('https://procura.example/login');
        $this->assertSame('https://procura.example/purchase-requests/140', route('purchase-requests.show', 140));
    }

    public function test_http_local_development_keeps_http_urls(): void
    {
        config(['app.url' => 'http://procura.example']);
        $this->app->getProvider(AppServiceProvider::class)->boot();

        $this->get('http://procura.example/login')->assertOk()
            ->assertSee('action="http://procura.example/login"', false)
            ->assertSee('http://procura.example/assets/images/asi-procura-logo-login.png', false);
    }
}
