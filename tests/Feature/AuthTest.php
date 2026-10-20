<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_login_endpoint_is_not_exposed(): void
    {
        $response = $this->postJson('/api/auth/login', []);
        $response->assertNotFound();
    }
}
