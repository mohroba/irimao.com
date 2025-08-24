<?php

namespace IMAOCustom\Services\Endpoints;

class UserCompetitionList {
    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ] );
        add_action( 'woocommerce_account_user-competition-list_endpoint', [ $this, 'content' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'user-competition-list', EP_ROOT | EP_PAGES );
    }

    public function menu_item( array $items ): array {
        $items['user-competition-list'] = 'مسابقات من';
        return $items;
    }

    public function content(): void {
        echo do_shortcode( '[crm_user_competitions]' );
    }
}
