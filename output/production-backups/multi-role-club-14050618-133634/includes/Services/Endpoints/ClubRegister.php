<?php
namespace IMAOCustom\Services\Endpoints;

use IMAOCustom\Forms\ClubRegisterForm;

class ClubRegister {
    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ] );
        add_action( 'woocommerce_account_club-register_endpoint', [ $this, 'content' ] );
        add_shortcode( 'club_register', [ $this, 'shortcode' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'club-register', EP_ROOT | EP_PAGES );
    }

    public function menu_item( array $items ): array {
        $user = wp_get_current_user();
        if ( is_user_logged_in() && in_array( 'coach', $user->roles, true ) && count( $user->roles ) === 1 ) {
            $items['club-register'] = 'ثبت باشگاه';
        }
        return $items;
    }

    public function content(): void {
        $form = new ClubRegisterForm();
        echo $form->render();
    }

    public function shortcode(): string {
        $form = new ClubRegisterForm();
        return $form->render();
    }
}
