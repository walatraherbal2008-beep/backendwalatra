<?php

namespace Tests\Feature;

use Tests\TestCase;

class RootLandingPageTest extends TestCase
{
    public function test_root_renders_the_walatra_landing_page_without_technical_navigation(): void
    {
        config(['services.company.whatsapp_url' => null]);

        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('Walatra Herbal')
            ->assertSee('Pilihan untuk setiap kebutuhan.')
            ->assertDontSee('Laravel')
            ->assertDontSee('/api/')
            ->assertDontSee('/admin')
            ->assertDontSee('/superadmin')
            ->assertDontSee('Hubungi Kami');
    }

    public function test_root_renders_whatsapp_cta_only_when_configured(): void
    {
        config(['services.company.whatsapp_url' => 'https://wa.me/628123456789']);

        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('Hubungi Kami')
            ->assertSee('href="https://wa.me/628123456789"', false);
    }
}
