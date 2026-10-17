<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_login_validation(): void
    {
        $response = $this->postJson('/api/auth/login', []);
        $response->assertStatus(422);
    }
}
