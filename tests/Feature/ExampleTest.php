<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_homepage_redirects_to_the_restaurant_login(): void
    {
        $this->get('/')
            ->assertRedirect(route('restaurant.login'));
    }
}
