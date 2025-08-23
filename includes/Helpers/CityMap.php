<?php

namespace IMAOCustom\Helpers;

class CityMap {
    private static ?array $cache = null;

    public static function get_map(): array {
        if ( self::$cache !== null ) {
            return self::$cache;
        }

        $root      = dirname( __DIR__, 2 );
        $prov_path = $root . '/provinces.json';
        $city_path = $root . '/cities.json';

        if ( ! file_exists( $prov_path ) || ! file_exists( $city_path ) ) {
            return self::$cache = [];
        }

        $provinces = json_decode( file_get_contents( $prov_path ), true ) ?: [];
        $cities    = json_decode( file_get_contents( $city_path ), true ) ?: [];

        $wc_states = class_exists( '\\WC_Countries' )
            ? ( new \WC_Countries() )->get_states( 'IR' )
            : self::default_states();

        $prov_code = [];
        foreach ( $provinces as $p ) {
            foreach ( $wc_states as $code => $name ) {
                if ( $name === $p['name'] ) {
                    $prov_code[ $p['id'] ] = $code;
                    break;
                }
            }
        }

        $map = [];
        foreach ( $cities as $c ) {
            $code = $prov_code[ $c['province_id'] ] ?? null;
            if ( $code ) {
                $map[ $code ][] = $c['name'];
            }
        }

        return self::$cache = $map;
    }

    public static function get_cities( string $province_code ): array {
        $map = self::get_map();
        return $map[ $province_code ] ?? [];
    }

    private static function default_states(): array {
        return [
            'IR-01' => 'آذربایجان شرقی',
            'IR-02' => 'آذربایجان غربی',
            'IR-03' => 'اردبیل',
            'IR-04' => 'اصفهان',
            'IR-05' => 'البرز',
            'IR-06' => 'ایلام',
            'IR-07' => 'بوشهر',
            'IR-08' => 'تهران',
            'IR-09' => 'چهارمحال و بختیاری',
            'IR-10' => 'خراسان جنوبی',
            'IR-11' => 'خراسان رضوی',
            'IR-12' => 'خراسان شمالی',
            'IR-13' => 'خوزستان',
            'IR-14' => 'زنجان',
            'IR-15' => 'سمنان',
            'IR-16' => 'سیستان و بلوچستان',
            'IR-17' => 'فارس',
            'IR-18' => 'قزوین',
            'IR-19' => 'قم',
            'IR-20' => 'کردستان',
            'IR-21' => 'کرمان',
            'IR-22' => 'کرمانشاه',
            'IR-23' => 'کهگیلویه و بویراحمد',
            'IR-24' => 'گلستان',
            'IR-25' => 'لرستان',
            'IR-26' => 'گیلان',
            'IR-27' => 'مازندران',
            'IR-28' => 'مرکزی',
            'IR-29' => 'هرمزگان',
            'IR-30' => 'همدان',
            'IR-31' => 'یزد',
        ];
    }
}

