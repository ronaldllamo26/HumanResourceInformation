<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpaLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_spa_login_returns_json(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@primepower.test',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $response = $this->postJson('/login', [
            'email' => 'admin@primepower.test',
            'password' => 'password',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'two_factor' => false,
        ]);
        $response->assertJsonStructure([
            'user' => ['id', 'name', 'email', 'role'],
        ]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_authenticated_user_can_access_api_dashboard_and_user(): void
    {
        $user = User::factory()->create([
            'email' => 'admin2@primepower.test',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $this->actingAs($user, 'web');

        $userResponse = $this->getJson('/api/user', [
            'Referer' => 'http://localhost:5173/',
        ]);
        $userResponse->assertStatus(200);

        $dashResponse = $this->getJson('/api/dashboard', [
            'Referer' => 'http://localhost:5173/',
        ]);
        $dashResponse->assertStatus(200);
    }

    public function test_inertia_endpoint_returns_json_props(): void
    {
        $user = User::factory()->create([
            'email' => 'admin3@primepower.test',
            'role' => 'admin',
        ]);

        $response = $this->actingAs($user)->get('/hr/employees', [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('component'));
        $this->assertNotNull($response->json('props'));

        $dashInertia = $this->actingAs($user)->get('/dashboard', [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        $dashInertia->assertStatus(200);
        dump(array_keys($dashInertia->json()));
        dump(array_keys($dashInertia->json('props')));
        $this->assertSame('Dashboard', $dashInertia->json('component'));
        $this->assertNotNull($dashInertia->json('props'));
    }
}
