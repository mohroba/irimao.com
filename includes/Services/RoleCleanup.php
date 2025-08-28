<?php
namespace IMAOCustom\Services;

/**
 * Remove the custom "club" role if it exists and has no users.
 */
class RoleCleanup {
    public function register(): void {
        add_action( 'init', [ $this, 'remove_unused_club_role' ] );
    }

    public function remove_unused_club_role(): void {
        if ( ! get_role( 'club' ) ) {
            return;
        }

        $users = get_users( [
            'role'   => 'club',
            'number' => 1,
            'fields' => 'ids',
        ] );

        if ( empty( $users ) ) {
            remove_role( 'club' );
        }
    }
}
