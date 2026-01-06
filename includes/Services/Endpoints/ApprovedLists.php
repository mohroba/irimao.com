<?php

namespace IMAOCustom\Services\Endpoints;

class ApprovedLists {
    public function register(): void {
        add_shortcode( 'crm_approved_coaches', [ $this, 'approved_coaches_shortcode' ] );
        add_shortcode( 'crm_approved_clubs', [ $this, 'approved_clubs_shortcode' ] );
    }

    public function approved_coaches_shortcode(): string {
        $users = get_users( [
            'role'       => 'coach',
            'meta_key'   => 'identity_verified_professional',
            'meta_value' => 'approved',
            'fields'     => [ 'ID', 'display_name' ],
            'orderby'    => 'display_name',
            'order'      => 'ASC',
        ] );
        if ( empty( $users ) ) {
            return '<p>هیچ مربی تایید شده‌ای یافت نشد.</p>';
        }
        $items = [];
        foreach ( $users as $user ) {
            $url     = get_author_posts_url( (int) $user->ID );
            $items[] = sprintf(
                '<li><a href="%s">%s</a></li>',
                esc_url( $url ),
                esc_html( $user->display_name )
            );
        }
        return '<ul class="crm-approved-coaches">' . implode( '', $items ) . '</ul>';
    }

    public function approved_clubs_shortcode(): string {
        $posts = get_posts( [
            'post_type'   => 'club_application',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ] );
        if ( empty( $posts ) ) {
            return '<p>هیچ باشگاه تایید شده‌ای یافت نشد.</p>';
        }
        $items = [];
        foreach ( $posts as $post ) {
            $url     = get_permalink( $post );
            $items[] = sprintf(
                '<li><a href="%s">%s</a></li>',
                esc_url( $url ),
                esc_html( $post->post_title )
            );
        }
        return '<ul class="crm-approved-clubs">' . implode( '', $items ) . '</ul>';
    }
}
