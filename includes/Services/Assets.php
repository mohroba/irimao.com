<?php
namespace IMAOCustom\Services;

class Assets {
    public function register(): void {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
    }

    public function enqueue_frontend(): void {
        $url = plugin_dir_url( dirname( __DIR__ ) );
        wp_enqueue_style( 'imao-my-account', $url . 'assets/css/my-account.css', [], '1.0.0' );
        wp_enqueue_script( 'imao-edit-basic-info', $url . 'assets/js/edit-basic-info.js', [ 'jquery' ], '1.0.0', true );
    }
}
