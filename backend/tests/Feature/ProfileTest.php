<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Settings\SettingsTest;
use Tests\TestCase;

/**
 * The starter kit's profile page was replaced by Settings > Security, which
 * carries the same behaviour plus API tokens and the audit log. What is left
 * here is the guarantee that the old URL still lands somewhere sensible —
 * bookmarks and the starter kit's own links point at it.
 *
 * @see SettingsTest for the behaviour itself.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_profile_url_redirects_to_settings_security(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/profile')
            ->assertRedirect('/settings/security');
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/profile')->assertRedirect('/login');
    }
}
