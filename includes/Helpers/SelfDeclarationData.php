<?php

namespace IMAOCustom\Helpers;

class SelfDeclarationData {
    public static function course_types(): array {
        return [
            1  => 'فنی',
            2  => 'داوری',
            3  => 'مربیگری',
            4  => 'قهرمانی',
            6  => 'بازآموزی',
            7  => 'دوره آموزشی',
            10 => 'کارورزی (عملی)',
            13 => 'استاژ فنی',
            14 => 'تربیت مدرس داوری',
        ];
    }

    public static function degree_options(): array {
        return [
            1 => self::list_degrees(),
            2 => [ '2' => 'درجه ۳','3' => 'درجه ۲','4' => 'درجه ۱','110' => 'درجه ملی کیک بوکسینگ' ],
            3 => [ '111' => 'درجه ۳','112' => 'درجه ۲','113' => 'درجه ۱' ],
            4 => [ '116' => 'مقام اول','117' => 'مقام دوم','118' => 'مقام سوم','119' => 'مقام سوم مشترک' ],
            6 => [ '107' => 'درجه سبکی - داوری','109' => 'درجه  هیاًت -  ملی کیک بوکسینگ','176' => 'درجه درجه فدراسیون - ملی کیک بوکسیبنگ' ],
            7 => [],
            10 => [ '104' => 'درجه ۳','105' => 'درجه ۲','106' => 'درجه ۱' ],
            13 => [ '175' => 'درجه استاژ فنی ' ],
            14 => [ '180' => 'درجه مدرس داوری ','181' => 'درجه دانش افزایی تربیت مدرس' ],
        ];
    }

    public static function degree_label( int $type, string $value ): string {
        if ( $value === '' ) {
            return '—';
        }
        $options = self::degree_options();
        return $options[ $type ][ $value ] ?? $value;
    }

    public static function list_degrees(): array {
        return [
            'yellow' => 'زرد',
            'orange' => 'نارنجی',
            'green'  => 'سبز',
            'blue'   => 'آبی',
            'brown'  => 'قهوه‌ای',
            'black'  => 'مشکی',
            'dan1'   => 'دان 1',
            'dan2'   => 'دان 2',
            'dan3'   => 'دان 3',
            'dan4'   => 'دان 4',
            'dan5'   => 'دان 5',
            'dan6'   => 'دان 6',
            'dan7'   => 'دان 7',
            'dan8'   => 'دان 8',
            'dan9'   => 'دان 9',
            'dan10'  => 'دان 10',
        ];
    }

    /**
     * @return array<int,array{label:string,weights:array<int,string>}>
     */
    public static function champion_age_map(): array {
        if ( ! function_exists( 'get_terms' ) ) {
            return [];
        }
        if ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( 'age_category' ) ) {
            return [];
        }
        $parents = get_terms([
            'taxonomy'   => 'age_category',
            'hide_empty' => false,
            'parent'     => 0,
        ]);
        $is_wp_error = function_exists( 'is_wp_error' ) ? 'is_wp_error' : null;
        if ( $is_wp_error && $is_wp_error( $parents ) ) {
            return [];
        }
        $map = [];
        foreach ( (array) $parents as $term ) {
            if ( is_object( $term ) ) {
                $term = (array) $term;
            }
            if ( empty( $term['term_id'] ) ) {
                continue;
            }
            $term_id = (int) $term['term_id'];
            $label   = (string) ( $term['name'] ?? $term_id );
            $weights = get_terms([
                'taxonomy'   => 'age_category',
                'hide_empty' => false,
                'parent'     => $term_id,
            ]);
            if ( $is_wp_error && $is_wp_error( $weights ) ) {
                $weights = [];
            }
            $weight_map = [];
            foreach ( (array) $weights as $weight ) {
                if ( is_object( $weight ) ) {
                    $weight = (array) $weight;
                }
                if ( empty( $weight['term_id'] ) ) {
                    continue;
                }
                $weight_map[ (int) $weight['term_id'] ] = (string) ( $weight['name'] ?? $weight['term_id'] );
            }
            $map[ $term_id ] = [
                'label'   => $label,
                'weights' => $weight_map,
            ];
        }
        return $map;
    }

    public static function view_map(): array {
        return [
            1  => '../certificates/technical.php?hokm_id=',
            2  => '../certificates/referee2.php?hokm_id=',
            3  => '../certificates/coaching2.php?hokm_id=',
            4  => '../certificates/champion.php?hokm_id=',
            6  => '../certificates/recertify.php?hokm_id=',
            7  => '../certificates/referee-trainer.php?hokm_id=',
            10 => '../certificates/coaching2.php?hokm_id=',
            13 => '../certificates/technical.php?hokm_id=',
        ];
    }
}

