<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedIfEmptyTest extends TestCase
{
    use RefreshDatabase;

    /** A first deploy has no terminal to seed from; without this nobody can sign in. */
    public function test_an_empty_database_is_seeded_with_the_role_accounts(): void
    {
        $this->artisan('hris:seed-if-empty')->assertSuccessful();

        foreach (['admin@primepower.com', 'hrstaff@primepower.com', 'employee@primepower.com'] as $username) {
            $this->assertDatabaseHas('users', ['username' => $username]);
        }
    }

    /** A restart must never re-issue passwords people have already changed. */
    public function test_a_database_with_accounts_is_left_alone(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'admin@primepower.com']);
        $password = $admin->password;

        $this->artisan('hris:seed-if-empty')
            ->expectsOutput('Accounts already exist — not seeding.')
            ->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertSame($password, $admin->fresh()->password);
    }
}
