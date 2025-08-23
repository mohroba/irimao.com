<?php

namespace IMAOCustom;

use IMAOCustom\Services\Assets;
use IMAOCustom\Services\Registration;
use IMAOCustom\Services\Endpoints\EditBasicInfo;
use IMAOCustom\Services\Endpoints\IdentityProfessional;
use IMAOCustom\Services\Endpoints\SmartcardIssue;

class Plugin {
    private static ?Plugin $instance = null;
    private array $services = [];

    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
        $this->register_services();
    }

    public static function get_instance(): Plugin {
        return self::$instance ??= new self();
    }

    public function load_textdomain(): void {
        load_plugin_textdomain( 'imao-custom-plugin', false, dirname( plugin_basename( __FILE__ ), 2 ) . '/languages' );
    }

    private function register_services(): void {
        $this->services = [
            new Assets(),
            new Registration(),
            new EditBasicInfo(),
            new IdentityProfessional(),
            new SmartcardIssue(),
        ];

        foreach ( $this->services as $service ) {
            if ( method_exists( $service, 'register' ) ) {
                $service->register();
            }
        }
    }
}

