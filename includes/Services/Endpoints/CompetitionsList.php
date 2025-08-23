<?php

namespace IMAOCustom\Services\Endpoints;

class CompetitionsList {
    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ] );
        add_action( 'woocommerce_account_competitions-list_endpoint', [ $this, 'content' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'competitions-list', EP_ROOT | EP_PAGES );
    }

    public function menu_item( array $items ): array {
        $items['competitions-list'] = 'مسابقات';
        return $items;
    }

    public function content(): void {
        echo do_shortcode( '[crm_competitions_list]' );
    }
}
