<?php

namespace IMAOCustom\Services;

use IMAOCustom\Helpers\Date;
use Morilog\Jalali\CalendarUtils;

/** Shared validation for class forms, listings and checkout. */
final class MonthlyClassRestrictions {
    public static function weekdays(): array {
        return [ 'saturday' => 'شنبه', 'sunday' => 'یکشنبه', 'monday' => 'دوشنبه',
            'tuesday' => 'سه‌شنبه', 'wednesday' => 'چهارشنبه', 'thursday' => 'پنجشنبه', 'friday' => 'جمعه' ];
    }

    public static function types(): array {
        return [ 'public' => 'عمومی', 'private' => 'خصوصی', 'semi-private' => 'نیمه خصوصی' ];
    }

    public static function age_categories(): array {
        $terms = get_terms( [ 'taxonomy' => 'age_category', 'hide_empty' => false, 'parent' => 0 ] );
        return is_wp_error( $terms ) ? [] : $terms;
    }

    public static function age_bounds( int $id ): ?array {
        $term = get_term( $id, 'age_category' );
        if ( ! $term || is_wp_error( $term ) || (int) $term->parent !== 0 ) {
            return null;
        }
        $min = get_term_meta( $id, 'age_start', true );
        $max = get_term_meta( $id, 'age_end', true );
        if ( ! is_scalar( $min ) || ! is_scalar( $max ) || ! ctype_digit( (string) $min )
            || ! ctype_digit( (string) $max ) || (int) $max < (int) $min ) {
            return null;
        }
        return [ (int) $min, (int) $max ];
    }

    /** Filter before LIMIT so result counts and pagination describe eligible classes. */
    public static function visibility_meta_query( int $user_id ): array {
        $query = [ 'relation' => 'OR', [ 'key' => '_class_age_category', 'compare' => 'NOT EXISTS' ] ];
        $age = self::student_age( $user_id );
        if ( $age === null ) {
            return $query;
        }
        $matching = [];
        foreach ( self::age_categories() as $term ) {
            $id = (int) $term->term_id;
            $bounds = self::age_bounds( $id );
            if ( $bounds !== null && $age >= $bounds[0] && $age <= $bounds[1] ) {
                $matching[] = $id;
            }
        }
        if ( $matching ) {
            $query[] = [ 'key' => '_class_age_category', 'value' => $matching, 'compare' => 'IN', 'type' => 'NUMERIC' ];
        }
        return $query;
    }

    private static function student_age( int $user_id ): ?int {
        $birth = get_user_meta( $user_id, 'birth_date', true );
        if ( ! is_string( $birth ) ) {
            return null;
        }
        $parts = Date::split( $birth );
        if ( count( $parts ) !== 3 || ! preg_match( '/^\d{4}$/D', $parts[0] )
            || ! preg_match( '/^\d{1,2}$/D', $parts[1] ) || ! preg_match( '/^\d{1,2}$/D', $parts[2] )
            || ! CalendarUtils::checkDate( (int) $parts[0], (int) $parts[1], (int) $parts[2] ) ) {
            return null;
        }
        return Date::age( implode( '/', $parts ) );
    }

    /** Invalid or incomplete submissions never erase existing restrictions. */
    public static function validate( $days, $age, $type ): ?array {
        if ( ! is_array( $days ) || ! $days || count( $days ) > 7
            || ! is_scalar( $age ) || ! ctype_digit( (string) $age ) || (int) $age < 1
            || ! is_string( $type ) || ! isset( self::types()[ $type ] ) ) {
            return null;
        }
        foreach ( $days as $day ) {
            if ( ! is_string( $day ) || ! isset( self::weekdays()[ $day ] ) ) {
                return null;
            }
        }
        if ( self::age_bounds( (int) $age ) === null ) {
            return null;
        }
        return [ '_class_weekdays' => array_values( array_intersect( array_keys( self::weekdays() ), $days ) ),
            '_class_age_category' => (int) $age, '_class_type' => $type ];
    }

    /** Empty means eligible; weekdays describe the schedule, not allowed payment dates. */
    public static function eligibility_error( int $class_id, int $user_id ): string {
        $category = get_post_meta( $class_id, '_class_age_category', true );
        if ( $category === '' ) {
            return '';
        }
        $bounds = is_scalar( $category ) && ctype_digit( (string) $category ) ? self::age_bounds( (int) $category ) : null;
        if ( $bounds === null ) {
            return 'رده سنی کلاس معتبر نیست؛ با مدیر سایت تماس بگیرید.';
        }
        $age = self::student_age( $user_id );
        if ( $age === null ) {
            return 'برای ثبت‌نام، تاریخ تولد معتبر خود را در اطلاعات پایه ثبت کنید.';
        }
        return $age >= $bounds[0] && $age <= $bounds[1] ? '' : 'سن شما با رده سنی این کلاس مطابقت ندارد.';
    }
}
