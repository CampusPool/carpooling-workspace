<?php

namespace Tests\Feature\Mobility;

use Tests\TestCase;

final class MobilityPilotPageTest extends TestCase
{
    public function test_pilot_requires_explicit_environment_enablement(): void
    {
        $configured = filter_var(getenv('MOBILITY_PILOT_UI_ENABLED'), FILTER_VALIDATE_BOOLEAN);
        $response = $this->get('/mobility-pilot');
        if (! $configured) {
            $response->assertNotFound();
            return;
        }

        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('Your everyday journey', $response->streamedContent());
        $this->assertStringNotContainsString('localStorage', $response->streamedContent());
    }

    public function test_pilot_assets_are_safely_served_without_external_scripts(): void
    {
        $configured = filter_var(getenv('MOBILITY_PILOT_UI_ENABLED'), FILTER_VALIDATE_BOOLEAN);
        foreach (['app.js', 'app.css'] as $file) {
            $response = $this->get('/mobility-pilot/'.$file);
            if (! $configured) {
                $response->assertNotFound();
            } else {
                $response->assertOk();
                $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
                $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
                $this->assertStringContainsString("script-src 'self'", (string) $response->headers->get('Content-Security-Policy'));
            }
        }
        $this->get('/mobility-pilot/secrets.php')->assertNotFound();
    }
}
