<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_frontend_origin_can_call_api_and_broadcast_auth_endpoints(): void
    {
        config([
            'cors.allowed_origins' => ['http://localhost:5173'],
        ]);

        $response = $this->call('OPTIONS', '/broadcasting/auth', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $response->assertNoContent()
            ->assertHeader('access-control-allow-origin', 'http://localhost:5173');
    }

    public function test_unconfigured_origin_does_not_receive_cors_permission(): void
    {
        config([
            'cors.allowed_origins' => ['http://localhost:5173'],
        ]);

        $response = $this->call('OPTIONS', '/api/profile', [], [], [], [
            'HTTP_ORIGIN' => 'https://untrusted.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $response->assertNoContent();
        $this->assertNotSame(
            'https://untrusted.example',
            $response->headers->get('access-control-allow-origin'),
        );
    }
}
