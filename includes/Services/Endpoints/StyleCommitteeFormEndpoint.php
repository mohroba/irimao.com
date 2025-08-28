<?php

namespace IMAOCustom\Services\Endpoints;

use IMAOCustom\Forms\StyleCommitteeForm;

class StyleCommitteeFormEndpoint {
    public function register(): void {
        register_activation_hook( IMAO_PLUGIN_FILE, [ $this, 'activate' ] );
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ] );
        add_action( 'woocommerce_account_style-committe-form_endpoint', [ $this, 'content' ] );
        add_shortcode( 'crm_style_committe_form', [ $this, 'shortcode' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'style-committe-form', EP_ROOT | EP_PAGES );
    }

    public function activate(): void {
        $this->add_endpoint();
        flush_rewrite_rules();
    }

    public function menu_item( array $items ): array {
        $items = array_slice( $items, 0, 1, true ) + [ 'style-committe-form' => 'کمیته‌های سبک' ] + array_slice( $items, 1, null, true );
        return $items;
    }

    public function content(): void {
        echo $this->render_form();
    }

    public function shortcode(): string {
        return $this->render_form();
    }

    private function render_form(): string {
        $form = new StyleCommitteeForm();
        return $form->render();
    }
}

