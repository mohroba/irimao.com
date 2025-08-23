<?php

namespace IMAOCustom\Services\Endpoints;

use IMAOCustom\Forms\SelfDeclarationForm;

class SelfDeclaration {
    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ] );
        add_action( 'woocommerce_account_self-declare_endpoint', [ $this, 'content' ] );
        add_shortcode( 'crm_self_declaration', [ $this, 'shortcode' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'self-declare', EP_ROOT | EP_PAGES );
    }

    public function menu_item( array $items ): array {
        $items = array_slice( $items, 0, 1, true ) + [ 'self-declare' => 'خوداظهاری' ] + array_slice( $items, 1, null, true );
        return $items;
    }

    public function content(): void {
        echo $this->render_form();
    }

    public function shortcode(): string {
        return $this->render_form();
    }

    private function render_form(): string {
        $form = new SelfDeclarationForm();
        return $form->render();
    }
}

