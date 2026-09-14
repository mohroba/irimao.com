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
        wp_enqueue_script( 'imao-sweetalert2', $url . 'assets/js/sweetalert2.all.min.js', [], '11.7.3', true );
        wp_add_inline_script(
            'imao-sweetalert2',
            "jQuery(function($){
    $('form.needs-swal').on('submit', function(){
        Swal.fire({
            title: 'در حال ارسال...',
            html: 'لطفاً صبر کنید',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => { Swal.showLoading(); }
        });
    });
});"
        );
        wp_enqueue_style( 'imao-self-declaration', $url . 'assets/css/self-declaration.css', [], '1.0.1' );
        wp_enqueue_style( 'imao-select2', $url . 'assets/css/select2.min.css', [], '1.0.0' );
        wp_enqueue_style( 'imao-identity-professional', $url . 'assets/css/identity-professional.css', [], '1.0.0' );
        wp_enqueue_style( 'imao-wallet', $url . 'assets/css/wallet.css', [], '1.0.0' );
        wp_enqueue_style( 'imao-club-register', $url . 'assets/css/club-register.css', [], '1.0.0' );
        wp_enqueue_style( 'imao-courses', $url . 'assets/css/courses.css', [], '1.0.0' );
        wp_enqueue_style( 'imao-jalali-datepicker-frontend', $url . 'assets/css/jalalidatepicker.min.css', [], '1.0.0' );
        wp_enqueue_script( 'imao-select2', $url . 'assets/js/select2.min.js', [ 'jquery' ], '1.0.0', true );
        wp_enqueue_script( 'imao-edit-basic-info', $url . 'assets/js/edit-basic-info.js', [ 'jquery', 'imao-select2' ], '1.0.0', true );
        wp_localize_script( 'imao-edit-basic-info', 'IMAOSD', [
            'degreeOptions'   => SelfDeclarationData::degree_options(),
            'championAgeMap'  => SelfDeclarationData::champion_age_map(),
        ] );
        wp_localize_script( 'imao-edit-basic-info', 'CBIF_CITIES', CityMap::get_map() );
        wp_enqueue_script( 'imao-identity-professional', $url . 'assets/js/identity-professional.js', [], '1.0.0', true );
        wp_enqueue_script( 'imao-club-register', $url . 'assets/js/club-register.js', [ 'jquery' ], '1.0.0', true );
        wp_enqueue_script( 'imao-vip-subscription', $url . 'assets/js/vip-subscription.js', [ 'jquery' ], '1.0.0', true );
        wp_enqueue_script( 'imao-jalali-datepicker-frontend', $url . 'assets/js/jalalidatepicker.min.js', [], '1.0.0', true );
        wp_add_inline_script( 'imao-jalali-datepicker-frontend', 'jalaliDatepicker.startWatch();' );
        wp_register_style( 'imao-competition-countdown', $url . 'assets/css/competition-countdown.css', [], '1.0.0' );
        wp_register_script( 'imao-competition-countdown', $url . 'assets/js/competition-countdown.js', [], '1.0.0', true );
        if ( ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_cart' ) && is_cart() ) ) {
            wp_enqueue_script( 'imao-wallet', $url . 'assets/js/wallet.js', [ 'jquery' ], '1.0.0', true );
        }
    }
}

