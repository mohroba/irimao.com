<?php
namespace IMAOCustom;

class Logger {
    public static function info( string $message, array $context = [] ): void {
        self::write( 'INFO', $message, $context );
    }

    public static function error( string $message, array $context = [] ): void {
        self::write( 'ERROR', $message, $context );
    }

    private static function write( string $level, string $message, array $context ): void {
        $context_str = $context ? json_encode( $context, JSON_UNESCAPED_SLASHES ) : '';
        error_log( sprintf( '[IMAOCustom][%s] %s %s', $level, $message, $context_str ) );
    }
}
