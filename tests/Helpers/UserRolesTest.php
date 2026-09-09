<?php

namespace Tests\Helpers;

use IMAOCustom\Helpers\UserRoles;
use PHPUnit\Framework\TestCase;

class UserRolesTest extends TestCase {
    public function test_coach_is_recognized_when_user_has_multiple_roles(): void {
        $user = (object) [ 'roles' => [ 'subscriber', 'coach' ] ];
        $this->assertTrue( UserRoles::has( $user, 'coach' ) );
    }

    public function test_user_without_coach_role_is_rejected(): void {
        $user = (object) [ 'roles' => [ 'subscriber', 'city_rep' ] ];
        $this->assertFalse( UserRoles::has( $user, 'coach' ) );
    }
}
