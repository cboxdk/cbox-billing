<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** The app is deny-by-default: a guest hitting the shell is bounced to sign-in. */
    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
