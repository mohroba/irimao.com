<?php
namespace IMAOCustom\Services\Endpoints;

use IMAOCustom\Forms\IdentityProfessionalForm;

class IdentityProfessional {
    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ] );
        add_action( 'woocommerce_account_identity-professional_endpoint', [ $this, 'content' ] );
        add_shortcode( 'crm_identity_professional', [ $this, 'shortcode' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'identity-professional', EP_ROOT | EP_PAGES );
    }

    public function menu_item( array $items ): array {
        $items['identity-professional'] = 'احراز هویت حرفه‌ای';
        return $items;
    }

    public function content(): void {
        echo $this->render_form();
    }

    public function shortcode(): string {
        return $this->render_form();
    }

    private function render_form(): string {
        $form = new IdentityProfessionalForm();
        return $form->render();
    }
}
