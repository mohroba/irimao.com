<?php
namespace IMAOCustom\Services;

class Avatar {
    public function register(): void {
        add_filter( 'pre_get_avatar_data', [ $this, 'filter_avatar' ], 10, 2 );
    }

    /**
     * Replace default avatar with the user's personal photo if available.
     *
     * @param array $args        Avatar arguments.
     * @param mixed $id_or_email User identifier.
     * @return array Modified avatar args.
     */
    public function filter_avatar( array $args, $id_or_email ): array {
        if ( is_numeric( $id_or_email ) ) {
            $user = get_user_by( 'id', (int) $id_or_email );
        } elseif ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) ) {
            $user = get_user_by( 'id', (int) $id_or_email->user_id );
        } elseif ( is_string( $id_or_email ) ) {
            $user = get_user_by( 'email', $id_or_email );
        } else {
            return $args;
        }

        if ( ! $user ) {
            return $args;
        }

        $url = get_user_meta( $user->ID, 'personal_photo', true );
        if ( $url ) {
            $args['url']          = esc_url_raw( $url );
            $args['found_avatar'] = true;
        }

        return $args;
    }
}
