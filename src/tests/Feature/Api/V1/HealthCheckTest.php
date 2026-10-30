<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
     public function test_health_endpoint_returns_success_response(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertExactJson([
                'success' => true,
                'data' => [
                    'status' => 'ok',
                    'service' => 'backend-system-api',
                ],
            ]);
    }
}
