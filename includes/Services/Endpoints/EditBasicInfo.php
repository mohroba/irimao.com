<?php

namespace IMAOCustom\Services\Endpoints;

use IMAOCustom\Forms\BasicInfoForm;

class EditBasicInfo {
    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ] );
        add_action( 'woocommerce_account_edit-basic-info_endpoint', [ $this, 'content' ] );
        add_shortcode( 'crm_edit_basic_info', [ $this, 'shortcode' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'edit-basic-info', EP_ROOT | EP_PAGES );
    }

    public function menu_item( array $items ): array {
        $items['edit-basic-info'] = 'ویرایش اطلاعات پایه';
        return $items;
    }

    public function content(): void {
        echo $this->render_form();
    }

    public function shortcode(): string {
        return $this->render_form();
    }

    private function render_form(): string {
        $form = new BasicInfoForm();
        return $form->render();
    }
}

