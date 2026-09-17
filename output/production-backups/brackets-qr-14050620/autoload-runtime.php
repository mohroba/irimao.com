<?php

// Stable plugin bootstrap for deployments where OPcache retains an older generated autoload.php.
require_once __DIR__ . '/vendor/composer/autoload_real.php';

foreach ( array_reverse( get_declared_classes() ) as $class_name ) {
    if ( strpos( $class_name, 'ComposerAutoloaderInit' ) === 0 && method_exists( $class_name, 'getLoader' ) ) {
        $class_name::getLoader();
        return;
    }
}

throw new RuntimeException( 'Composer autoloader initializer was not found.' );
