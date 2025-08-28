<?php
namespace IMAOCustom\Services {
    function get_role( $role ) { return $role === 'club' ? (object) [] : null; }
    function get_users( array $args ) { return $GLOBALS['users_with_role'] ?? []; }
    function remove_role( $role ) { $GLOBALS['removed_role'] = $role; }
}

namespace Tests\Services {
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\RoleCleanup;

class RoleCleanupTest extends TestCase {
    protected function setUp(): void {
        unset( $GLOBALS['removed_role'], $GLOBALS['users_with_role'] );
    }

    public function test_removes_club_role_when_unused(): void {
        $GLOBALS['users_with_role'] = [];
        ( new RoleCleanup() )->remove_unused_club_role();
        $this->assertSame( 'club', $GLOBALS['removed_role'] );
    }

    public function test_keeps_club_role_when_used(): void {
        $GLOBALS['users_with_role'] = [ 1 ];
        ( new RoleCleanup() )->remove_unused_club_role();
        $this->assertArrayNotHasKey( 'removed_role', $GLOBALS );
    }
}
}
