<?php
namespace IMAOCustom\Helpers;

class Bootstrap {
    public static function enqueue(): void {
        $ver = '5.3.2';
        wp_enqueue_style(
            'bootstrap-css',
            'https://cdn.jsdelivr.net/npm/bootstrap@' . $ver . '/dist/css/bootstrap.min.css',
            [],
            $ver
        );
        wp_enqueue_script(
            'bootstrap-js',
            'https://cdn.jsdelivr.net/npm/bootstrap@' . $ver . '/dist/js/bootstrap.bundle.min.js',
            [],
            $ver,
            true
        );
    }
}
