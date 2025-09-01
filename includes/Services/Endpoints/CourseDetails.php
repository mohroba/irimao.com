<?php

namespace IMAOCustom\Services\Endpoints;

use IMAOCustom\Helpers\UserMeta;

class CourseDetails {
    private const META_LINKED_PRODUCT = '_linked_product_id';
    private const META_LINKED_COURSE  = '_linked_post_id';

    /**
     * Fields displayed on course details page.
     *
     * @return array<string,string>
     */
    public static function fields(): array {
        return [
            'course_code'        => 'کد دوره',
            'course_type'        => 'نوع دوره',
            'course_name'        => 'نام دوره',
            'start_date'         => 'تاریخ شروع دوره',
            'end_date'           => 'تاریخ پایان دوره',
            'exam_date'          => 'تاریخ آزمون',
            'registration_start' => 'شروع ثبت‌نام',
            'registration_end'   => 'پایان ثبت‌نام',
            'board'              => 'استان',
            'gender'             => 'جنسیت دوره',
            'level'              => 'سطح دوره',
            'attendance'         => 'نوع حضور',
            'course_time'        => 'ساعت برگزاری دوره',
            'organizer'          => 'مسئول برگزاری',
            'organizer_tel'      => 'شماره همراه مسئول برگزاری',
            'instructor'         => 'مدرس دوره',
            'examiner'           => 'ممتحن',
            'supervisor'         => 'ناظر',
            'address'            => 'آدرس محل برگزاری',
            'min_degree'         => 'حداقل درجه فنی',
            'points'             => 'امتیاز دوره',
            'price'              => 'شهریه دوره',
        ];
    }

    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_action( 'woocommerce_account_course-details_endpoint', [ $this, 'content' ] );
        add_shortcode( 'crm_course_details', [ $this, 'shortcode' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'course-details', EP_ROOT | EP_PAGES );
    }

    public function content(): void {
        echo $this->render();
    }

    public function shortcode( array $atts = [] ): string {
        $atts = shortcode_atts( [ 'id' => 0 ], $atts, 'crm_course_details' );
        return $this->render( (int) $atts['id'] );
    }

    private function render( int $cid = 0 ): string {
        if ( ! $cid && isset( $_GET['course_id'] ) ) {
            $cid = (int) $_GET['course_id'];
        }
        if ( ! $cid && isset( $_GET['id'] ) ) {
            $cid = (int) $_GET['id'];
        }
        if ( ! $cid && is_singular( 'course' ) ) {
            $cid = get_the_ID();
        }
        if ( ! $cid || get_post_type( $cid ) !== 'course' ) {
            return '<p style="text-align:center;color:#c00;">دوره پیدا نشد.</p>';
        }
        if ( ! is_user_logged_in() ) {
            return '<p style="text-align:center;color:#c00;">برای ثبت‌نام ابتدا وارد شوید.</p>';
        }
        $uid    = get_current_user_id();
        $gender = UserMeta::gender_slug( $uid );
        $status = (string) get_user_meta( $uid, 'identity_verified_professional', true );
        if ( ! $gender || $status !== 'approved' ) {
            return '<p style="text-align:center;color:#c00;">برای ثبت‌نام در دوره‌ها، ابتدا اطلاعات پایه را تکمیل و هویت خود را تأیید کنید.</p>';
        }
        $gterms = wp_get_post_terms( $cid, 'gender', [ 'fields' => 'slugs' ] );
        if ( $gterms && ! in_array( $gender, $gterms, true ) ) {
            return '<p style="text-align:center;color:#c00;">این دوره با جنسیت شما سازگار نیست.</p>';
        }

        $fields     = self::fields();
        $tax_fields = [ 'course_type', 'board', 'gender', 'level' ];
        $prod_id    = (int) get_post_meta( $cid, self::META_LINKED_PRODUCT, true );
        if ( ! $prod_id ) {
            $prod_id = $this->sync_product( $cid );
        }
        $cart_link = wc_get_cart_url() . '?add-to-cart=' . $prod_id;
        ob_start();
        ?>
        <div class="crm-single-course">
            <h3><?php echo esc_html( get_the_title( $cid ) ); ?></h3>
            <table class="striped">
                <tbody>
                    <?php foreach ( $fields as $key => $label ) :
                        if ( $key === 'course_name' ) {
                            $val = get_the_title( $cid );
                        } elseif ( in_array( $key, $tax_fields, true ) ) {
                            $terms = get_the_terms( $cid, $key );
                            $val   = $terms && ! is_wp_error( $terms ) ? join( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
                        } else {
                            $val = get_post_meta( $cid, $key, true );
                            if ( $key === 'price' ) {
                                $val = wc_price( (float) $val );
                            }
                        }
                    ?>
                        <tr>
                            <th><?php echo esc_html( $label ); ?></th>
                            <td><?php echo $val ? $val : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="text-align:center">
                <a class="crm-buy-btn" href="<?php echo esc_url( $cart_link ); ?>">پرداخت و ثبت‌نام</a>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    private function sync_product( int $course_id ): int {
        if ( ! class_exists( 'WC_Product' ) ) {
            return 0;
        }
        $price   = (float) get_post_meta( $course_id, 'price', true );
        $prod_id = (int) get_post_meta( $course_id, self::META_LINKED_PRODUCT, true );
        if ( $prod_id && ( $prod = wc_get_product( $prod_id ) ) ) {
            $prod->set_name( get_the_title( $course_id ) );
            $prod->set_regular_price( $price );
            $prod->save();
        } else {
            $prod = new \WC_Product_Simple();
            $prod->set_name( get_the_title( $course_id ) );
            $prod->set_regular_price( $price );
            $prod->set_virtual( true );
            $prod->set_catalog_visibility( 'hidden' );
            $prod_id = $prod->save();
            update_post_meta( $course_id, self::META_LINKED_PRODUCT, $prod_id );
            update_post_meta( $prod_id, self::META_LINKED_COURSE, $course_id );
        }
        return $prod_id;
    }
}
