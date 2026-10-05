<?php

namespace Tests\Feature\PncAgentics;

use App\Exceptions\Handlers\Pnc500Handler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Pnc500HandlerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function handler_returns_structured_error_response(): void
    {
        $response = $this->call('GET', '/non-existent-route-that-500s');

        $response->assertStatus(500);
        $response->assertJsonStructure([
            'errors' => [
                '*' => [
                    'message',
                    'correlation_id',
                ],
            ],
        ]);
    }

    /** @test */
    public function handler_includes_correlation_id_in_response(): void
    {
        $response = $this->call('GET', '/non-existent-route-that-500s');

        $response->assertStatus(500);

        $json = $response->json();
        $this->assertArrayHasKey('correlation_id', $json['errors'][0]);
        $this->assertNotEmpty($json['errors'][0]['correlation_id']);
        // Should be a valid UUID
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $json['errors'][0]['correlation_id']
        );
    }

    /** @test */
    public function handler_does_not_expose_stack_trace_in_production(): void
    {
        config()->set('app.debug', false);

        $response = $this->call('GET', '/non-existent-route-that-500s');

        $response->assertStatus(500);

        $body = $response->getContent();
        $this->assertStringNotContainsString('Stack trace', $body);
        $this->assertStringNotContainsString('at ', $body);
    }
}
