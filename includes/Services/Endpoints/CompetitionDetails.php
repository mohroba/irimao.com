<?php

namespace IMAOCustom\Services\Endpoints;

class CompetitionDetails {
    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_action( 'woocommerce_account_competition-details_endpoint', [ $this, 'content' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'competition-details', EP_ROOT | EP_PAGES );
    }

    public function content(): void {
        echo do_shortcode( '[crm_competition_details]' );
    }
}
