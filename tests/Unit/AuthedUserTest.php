<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\AuthedUser;
use Tests\TestCase;

class AuthedUserTest extends TestCase
{
    public function test_org_label_uses_the_org_name_claim_when_present(): void
    {
        $user = AuthedUser::fromClaims([
            'sub' => 'user_1',
            'name' => 'Sylvester Damgaard',
            'email' => 'sn@cbox.dk',
            'org' => '01kxkee9cyebrmawq1103fmr5t',
            'org_name' => 'Cbox Systems',
        ]);

        $this->assertSame('Cbox Systems', $user->orgLabel());
        $this->assertSame('CS', $user->orgInitials());
    }

    public function test_org_label_falls_back_to_the_org_id_then_personal(): void
    {
        $withId = AuthedUser::fromClaims([
            'sub' => 'user_1',
            'org' => '01kxkee9cyebrmawq1103fmr5t',
        ]);
        $this->assertSame('01kxkee9cyebrmawq1103fmr5t', $withId->orgLabel());

        $unscoped = AuthedUser::fromClaims(['sub' => 'user_1']);
        $this->assertSame('Personal', $unscoped->orgLabel());
    }

    public function test_org_name_round_trips_through_the_session_array(): void
    {
        $user = AuthedUser::fromClaims([
            'sub' => 'user_1',
            'org' => 'org_1',
            'org_name' => 'Meridian Labs',
        ]);

        $rehydrated = AuthedUser::fromArray($user->toArray());

        $this->assertSame('Meridian Labs', $rehydrated->orgName);
        $this->assertSame('Meridian Labs', $rehydrated->orgLabel());
    }
}
