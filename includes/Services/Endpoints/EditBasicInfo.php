<?php
namespace IMAOCustom\Services\Endpoints;

use IMAOCustom\Forms\BasicInfoForm;

class EditBasicInfo {
    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ] );
        add_action( 'woocommerce_account_edit-basic-info_endpoint', [ $this, 'content' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'edit-basic-info', EP_ROOT | EP_PAGES );
    }

    public function menu_item( array $items ): array {
        $items['edit-basic-info'] = 'ویرایش اطلاعات پایه';
        return $items;
    }

    public function content(): void {
        $form = new BasicInfoForm();
        echo $form->render();
    }
}
