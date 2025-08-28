<?php
namespace IMAOCustom\Services {
    // Simple user object to record roles
    class WP_User {
        public int $ID;
        public array $roles = [];
        public function __construct( int $id ) { $this->ID = $id; }
        public function add_role( string $role ): void { $this->roles[] = $role; }
    }
    // Globals to simulate storage
    $GLOBALS['club_app_users'] = [];
    $GLOBALS['club_app_meta']  = [];
    function get_userdata( $uid ) { return $GLOBALS['club_app_users'][ $uid ] ?? null; }
    function get_user_meta( $uid, $key, $single = true ) { return $GLOBALS['club_app_meta'][ $uid ][ $key ] ?? ''; }
    function update_user_meta( $uid, $key, $value ) { $GLOBALS['club_app_meta'][ $uid ][ $key ] = $value; }
}

namespace Tests\Services {
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\ClubApplications;
use IMAOCustom\Services\WP_User;

class ClubApplicationsApprovalTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['club_app_users'] = [];
        $GLOBALS['club_app_meta']  = [];
    }

    public function test_first_club_is_recorded(): void {
        $user = new WP_User(10);
        $GLOBALS['club_app_users'][10] = $user;
        $service = new ClubApplications();
        $service->approve_application(1, 10);
        $this->assertSame([1], $GLOBALS['club_app_meta'][10]['clubs']);
        $this->assertSame([], $user->roles);
    }

    public function test_second_club_is_appended(): void {
        $user = new WP_User(20);
        $GLOBALS['club_app_users'][20] = $user;
        $GLOBALS['club_app_meta'][20]['clubs'] = [5];
        $service = new ClubApplications();
        $service->approve_application(6, 20);
        $this->assertSame([5, 6], $GLOBALS['club_app_meta'][20]['clubs']);
        $this->assertSame([], $user->roles);
    }
}
}
