<?php
namespace IMAOCustom\Services;

use IMAOCustom\Helpers\UserMeta;
use IMAOCustom\Helpers\AgeCategory;
use IMAOCustom\Helpers\Date;

class Eligibility {
    public function register(): void {
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate' ], 10, 3 );
    }

    public function validate( bool $passed, int $product_id, int $quantity ): bool {
        $post_id = (int) get_post_meta( $product_id, '_linked_post_id', true );
        if ( ! $post_id ) {
            return $passed;
        }
        $type = get_post_type( $post_id );
        if ( ! in_array( $type, [ 'course', 'competition' ], true ) ) {
            return $passed;
        }
        if ( ! is_user_logged_in() ) {
            wc_add_notice( 'برای ثبت‌نام ابتدا وارد شوید.', 'error' );
            return false;
        }
        $uid    = get_current_user_id();
        $gender = UserMeta::gender_slug( $uid );
        $status = (string) get_user_meta( $uid, 'identity_verified_professional', true );
        if ( ! $gender || $status !== 'approved' ) {
            wc_add_notice( 'برای ثبت‌نام، ابتدا اطلاعات پایه را تکمیل و هویت خود را تأیید کنید.', 'error' );
            return false;
        }
        $gterms = wp_get_post_terms( $post_id, 'gender', [ 'fields' => 'slugs' ] );
        if ( $gterms && ! in_array( $gender, $gterms, true ) ) {
            $message = $type === 'competition'
                ? 'این مسابقات با جنسیت شما مطابقت ندارد، لطفا در انتخاب مسابقات دقت فرمایید.'
                : 'این مورد با جنسیت شما سازگار نیست.';
            wc_add_notice( $message, 'error' );
            return false;
        }
        if ( $type === 'competition' ) {
            $birth = (string) UserMeta::get( $uid, 'birth_date', '' );
            $start = (string) get_post_meta( $post_id, 'start_date', true );
            $age   = $birth !== '' && $start !== '' ? Date::age( $birth, $start ) : null;
            $slug  = $age !== null ? AgeCategory::slug_from_age( $age ) : '';
            if ( $slug === '' ) {
                wc_add_notice( 'رده سنی مسابقه قابل تعیین نیست؛ تاریخ تولد و تاریخ برگزاری مسابقه را بررسی کنید.', 'error' );
                return false;
            }
            $age_terms = wp_get_post_terms( $post_id, 'age_category', [ 'fields' => 'slugs' ] );
            if ( $slug !== '' && $age_terms && ! in_array( $slug, $age_terms, true ) ) {
                wc_add_notice( 'این مسابقات با رده ی سنی شما مطابقت ندارد، لطفا در انتخاب مسابقات دقت فرمایید.', 'error' );
                return false;
            }
        }
        return $passed;
    }
}
