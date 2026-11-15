<?php

namespace IMAOCustom\Services {
    function get_term( $id, $taxonomy ) {
        return $GLOBALS['restriction_terms'][$id] ?? false;
    }
    function get_term_meta( $id, $key, $single = true ) {
        return $GLOBALS['restriction_bounds'][$id][$key] ?? '';
    }
    function is_wp_error( $value ) { return false; }
    function get_post_meta( $id, $key, $single = true ) {
        return $GLOBALS['restriction_class'][$key] ?? '';
    }
    function get_user_meta( $id, $key, $single = true ) {
        return $GLOBALS['restriction_birth'] ?? '';
    }
    function get_terms( $args ) { return array_values( $GLOBALS['restriction_terms'] ); }
    function selected( $value, $expected, $echo = true ) { return (string) $value === (string) $expected ? 'selected="selected"' : ''; }
    function checked( $value, $expected, $echo = true ) { return $value === $expected ? 'checked="checked"' : ''; }
    function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
    function wp_unslash( $value ) { return $value; }
}

namespace IMAOCustom\Tests\Services {
    use IMAOCustom\Services\MonthlyClasses;
    use IMAOCustom\Services\MonthlyClassRestrictions;
    use IMAOCustom\Helpers\Date;
    use PHPUnit\Framework\TestCase;

    final class MonthlyClassRestrictionsTest extends TestCase {
        protected function setUp(): void {
            $GLOBALS['restriction_terms'] = [ 10 => (object) [ 'term_id' => 10, 'parent' => 0, 'name' => 'نوجوانان' ],
                11 => (object) [ 'term_id' => 11, 'parent' => 10, 'name' => 'دسته وزنی' ] ];
            $GLOBALS['restriction_bounds'] = [ 10 => [ 'age_start' => '10', 'age_end' => '18' ] ];
            $GLOBALS['restriction_class'] = [];
            $GLOBALS['restriction_birth'] = '';
        }

        public function test_valid_input_has_canonical_order_and_unique_days(): void {
            $result = MonthlyClassRestrictions::validate( [ 'monday', 'saturday', 'monday' ], '10', 'semi-private' );
            $this->assertSame( [ 'saturday', 'monday' ], $result['_class_weekdays'] );
            $this->assertSame( 10, $result['_class_age_category'] );
            foreach ( array_keys( MonthlyClassRestrictions::types() ) as $type ) {
                $this->assertNotNull( MonthlyClassRestrictions::validate( [ 'friday' ], 10, $type ) );
            }
        }

        public function test_admin_and_coach_input_contracts_are_identical(): void {
            $method = new \ReflectionMethod( MonthlyClasses::class, 'submitted_restrictions' );
            $method->setAccessible( true );
            foreach ( [ true, false ] as $admin ) {
                $prefix = $admin ? '_class_' : 'class_';
                $_POST = [ $prefix . 'weekdays' => [ 'friday' ], $prefix . 'age_category' => '10', $prefix . 'type' => 'private' ];
                $result = $method->invoke( null, $admin );
                $this->assertSame( 'private', $result['_class_type'] );
                unset( $_POST[ $prefix . 'age_category' ] );
                $this->assertNull( $method->invoke( null, $admin ) );
            }
            $_POST = [];
        }

        public function test_create_and_edit_forms_render_required_choices_and_saved_values(): void {
            $method = new \ReflectionMethod( MonthlyClasses::class, 'restriction_fields' );
            $method->setAccessible( true );
            foreach ( [ true, false ] as $admin ) {
                foreach ( [ 0, 1 ] as $id ) {
                    $GLOBALS['restriction_class'] = [ '_class_weekdays' => [ 'friday' ], '_class_age_category' => 10, '_class_type' => 'private' ];
                    ob_start();
                    $method->invoke( new MonthlyClasses(), $id, $admin );
                    $html = ob_get_clean();
                    $prefix = $admin ? '_class_' : 'class_';
                    $this->assertStringContainsString( 'name="' . $prefix . 'weekdays[]"', $html );
                    $this->assertSame( 2, substr_count( $html, 'required' ) );
                    $this->assertSame( 7, substr_count( $html, 'type="checkbox"' ) );
                    $this->assertStringNotContainsString( 'دسته وزنی', $html );
                    $this->assertStringContainsString( 'نیمه خصوصی', $html );
                    $this->assertSame( $id ? 2 : 0, substr_count( $html, 'selected="selected"' ) );
                    $this->assertSame( $id ? 1 : 0, substr_count( $html, 'checked="checked"' ) );
                }
            }
        }

        public function test_each_type_renders_a_distinct_badge_with_literal_label(): void {
            $method = new \ReflectionMethod( MonthlyClasses::class, 'restriction_summary' );
            $method->setAccessible( true );
            foreach ( MonthlyClassRestrictions::types() as $type => $label ) {
                $GLOBALS['restriction_class']['_class_type'] = $type;
                ob_start();
                $method->invoke( new MonthlyClasses(), 1 );
                $html = ob_get_clean();
                $this->assertStringContainsString( 'imao-class-type--' . $type, $html );
                $this->assertStringContainsString( 'data-class-type="' . $type . '"', $html );
                $this->assertStringContainsString( '>' . $label . '</span>', $html );
            }
        }

        /** @dataProvider invalid_inputs */
        public function test_invalid_metadata_is_rejected( $days, $age, $type ): void {
            $this->assertNull( MonthlyClassRestrictions::validate( $days, $age, $type ) );
        }

        public function invalid_inputs(): array {
            return [ [ [], 10, 'public' ], [ 'saturday', 10, 'public' ], [ [ [] ], 10, 'public' ],
                [ [ 'holiday' ], 10, 'public' ], [ [ 'saturday' ], [], 'public' ],
                [ [ 'saturday' ], 11, 'public' ], [ [ 'saturday' ], 99, 'public' ],
                [ [ 'saturday' ], 10, 'unknown' ], [ [ 'saturday' ], 10, [] ] ];
        }

        public function test_missing_legacy_metadata_is_unrestricted_without_birth_date(): void {
            $this->assertSame( '', MonthlyClassRestrictions::eligibility_error( 1, 2 ) );
            MonthlyClasses::assert_student_eligible( 1, 2 );
        }

        public function test_jalali_birth_age_and_inclusive_bounds(): void {
            $GLOBALS['restriction_birth'] = '۱۳۹۰/۰۲/۰۳';
            $age = Date::age( $GLOBALS['restriction_birth'] );
            $this->assertNotNull( $age );
            $GLOBALS['restriction_class']['_class_age_category'] = 10;
            foreach ( [ [ $age, $age + 1 ], [ $age - 1, $age ] ] as $bounds ) {
                $GLOBALS['restriction_bounds'][10] = [ 'age_start' => (string) $bounds[0], 'age_end' => (string) $bounds[1] ];
                $this->assertSame( '', MonthlyClassRestrictions::eligibility_error( 1, 2 ) );
            }
            $GLOBALS['restriction_bounds'][10]['age_end'] = (string) ( $age - 1 );
            $this->expectException( \RuntimeException::class );
            MonthlyClasses::assert_student_eligible( 1, 2 );
        }

        public function test_restricted_class_rejects_missing_invalid_and_future_birth_dates(): void {
            $GLOBALS['restriction_class']['_class_age_category'] = 10;
            foreach ( [ '', 'invalid', '۱۵۰۰/۰۱/۰۱' ] as $birth ) {
                $GLOBALS['restriction_birth'] = $birth;
                $this->assertNotSame( '', MonthlyClassRestrictions::eligibility_error( 1, 2 ) );
            }
        }

        public function test_deleted_or_malformed_category_and_invalid_bounds_fail_closed(): void {
            foreach ( [ 99, [], '0' ] as $category ) {
                $GLOBALS['restriction_class']['_class_age_category'] = $category;
                $this->assertNotSame( '', MonthlyClassRestrictions::eligibility_error( 1, 2 ) );
            }
            foreach ( [ [ '', '18' ], [ '18', '10' ], [ [], '18' ] ] as $bounds ) {
                $GLOBALS['restriction_bounds'][10] = [ 'age_start' => $bounds[0], 'age_end' => $bounds[1] ];
                $this->assertNull( MonthlyClassRestrictions::validate( [ 'friday' ], 10, 'public' ) );
            }
        }
    }
}
