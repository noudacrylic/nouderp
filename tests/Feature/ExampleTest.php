<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // Tamu di '/' diarahkan ke /login (lihat routes/web.php).
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
