<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** The root path is an entry point into the dashboard, not a landing page. */
    public function test_the_root_path_redirects_to_the_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_guests_are_sent_to_the_login_screen(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login', absolute: false));
    }
}
