<?php

namespace IMAOCustom\Helpers;

class UserRoles {
    public static function has( $user, string $role ): bool {
        if ( ! is_object( $user ) || $role === '' ) {
            return false;
        }
        $roles = is_array( $user->roles ?? null ) ? $user->roles : [];
        return in_array( $role, $roles, true );
    }
}
