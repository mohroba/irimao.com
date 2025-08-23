<?php

namespace IMAOCustom\Services\Endpoints;

use IMAOCustom\Forms\SelfDeclarationsList;

class SelfDeclarationsListEndpoint {
    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ] );
        add_action( 'woocommerce_account_self-declarations-list_endpoint', [ $this, 'content' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'self-declarations-list', EP_ROOT | EP_PAGES );
    }

    public function menu_item( array $items ): array {
        $items['self-declarations-list'] = 'لیست احکام ثبت شده';
        return $items;
    }

    public function content(): void {
        $form = new SelfDeclarationsList();
        echo $form->render();
    }
}

