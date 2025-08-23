<?php

namespace IMAOCustom\Services;
use IMAOCustom\Helpers\SelfDeclarationData;

use IMAOCustom\Helpers\CityMap;

class Assets {
    public function register(): void {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
    }

    public function enqueue_frontend(): void {
        $url = plugin_dir_url( dirname( __DIR__ ) );
        wp_enqueue_style( 'imao-my-account', $url . 'assets/css/my-account.css', [], '1.0.0' );
        wp_enqueue_style( 'imao-self-declaration', $url . 'assets/css/self-declaration.css', [], '1.0.0' );
        wp_enqueue_style( 'select2', $url . 'assets/css/select2.min.css', [], '4.0.13' );
        wp_enqueue_style( 'jalali-datepicker', $url . 'assets/css/jalalidatepicker.min.css', [], '1.0.0' );

        wp_enqueue_script( 'select2', $url . 'assets/js/select2.min.js', [ 'jquery' ], '4.0.13', true );
        wp_enqueue_script( 'jalali-datepicker', $url . 'assets/js/jalalidatepicker.min.js', [ 'jquery' ], '1.0.0', true );
        wp_enqueue_script( 'imao-edit-basic-info', $url . 'assets/js/edit-basic-info.js', [ 'jquery', 'select2', 'jalali-datepicker' ], '1.0.0', true );
        wp_localize_script( 'imao-edit-basic-info', 'IMAOSD', [
            'degreeOptions' => SelfDeclarationData::degree_options(),
        ] );
        wp_enqueue_style( 'imao-select2', $url . 'assets/css/select2.min.css', [], '1.0.0' );
        wp_enqueue_style( 'imao-jdp', $url . 'assets/css/jalalidatepicker.min.css', [], '1.0.0' );
        wp_enqueue_style( 'imao-identity-professional', $url . 'assets/css/identity-professional.css', [], '1.0.0' );
        wp_enqueue_script( 'imao-select2', $url . 'assets/js/select2.min.js', [ 'jquery' ], '1.0.0', true );
        wp_enqueue_script( 'imao-jdp', $url . 'assets/js/jalalidatepicker.min.js', [], '1.0.0', true );
        wp_enqueue_script( 'imao-edit-basic-info', $url . 'assets/js/edit-basic-info.js', [ 'jquery', 'imao-select2', 'imao-jdp' ], '1.0.0', true );
        wp_localize_script( 'imao-edit-basic-info', 'CBIF_CITIES', CityMap::get_map() );
        wp_enqueue_script( 'imao-identity-professional', $url . 'assets/js/identity-professional.js', [], '1.0.0', true );
    }
}

