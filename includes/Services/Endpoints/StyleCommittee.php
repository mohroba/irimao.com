<?php

namespace IMAOCustom\Services\Endpoints;

/**
 * Handles the "Style Committee" endpoint within the WooCommerce My Account area.
 */
class StyleCommittee {

    /**
     * Registers WordPress hooks.
     */
    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_action( 'woocommerce_account_style-committe_endpoint', [ $this, 'content' ] );
    }

    /**
     * Adds the rewrite endpoint for the Style Committee page.
     */
    public function add_endpoint(): void {
        add_rewrite_endpoint( 'style-committe', EP_ROOT | EP_PAGES );
    }

    /**
     * Renders the endpoint content.
     */
    public function content(): void {
        echo $this->render();
    }

    /**
     * Returns the Elementor template shortcode output.
     */
    public function render(): string {
        return do_shortcode( '[elementor-template id="4023"]' );
    }
}

