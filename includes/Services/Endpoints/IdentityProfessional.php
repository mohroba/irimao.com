<?php
namespace IMAOCustom\Services\Endpoints;

use IMAOCustom\Forms\IdentityProfessionalForm;

class IdentityProfessional {
    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ] );
        add_action( 'woocommerce_account_identity-professional_endpoint', [ $this, 'content' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'identity-professional', EP_ROOT | EP_PAGES );
    }

    public function menu_item( array $items ): array {
        $items['identity-professional'] = 'احراز هویت حرفه‌ای';
        return $items;
    }

    public function content(): void {
        $form = new IdentityProfessionalForm();
        echo $form->render();
    }
}
