<?php

namespace IMAOCustom\Services\Endpoints;

use IMAOCustom\Forms\SmartcardIssueForm;

class SmartcardIssue {
    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ] );
        add_action( 'woocommerce_account_smartcard-issue_endpoint', [ $this, 'content' ] );
        add_shortcode( 'crm_smartcard_issue', [ $this, 'shortcode' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'smartcard-issue', EP_ROOT | EP_PAGES );
    }

    public function menu_item( array $items ): array {
        $items['smartcard-issue'] = 'صدور/تمدید کارت';
        return $items;
    }

    public function content(): void {
        echo $this->render_form();
    }

    public function shortcode(): string {
        return $this->render_form();
    }

    private function render_form(): string {
        $form = new SmartcardIssueForm();
        return $form->render();
    }
}
