<?php

namespace IMAOCustom\Services\Endpoints;

use IMAOCustom\Forms\PasswordChangeForm;

class ChangePassword {
    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ] );
        add_action( 'woocommerce_account_change-password_endpoint', [ $this, 'content' ] );
        add_shortcode( 'crm_change_password', [ $this, 'shortcode' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'change-password', EP_ROOT | EP_PAGES );
    }

    public function menu_item( array $items ): array {
        $items['change-password'] = 'تغییر رمز عبور';
        return $items;
    }

    public function content(): void {
        echo do_shortcode( '[crm_change_password]' );
    }

    public function shortcode(): string {
        return $this->render_form();
    }

    private function render_form(): string {
        $form = new PasswordChangeForm();
        return $form->render();
    }
}
