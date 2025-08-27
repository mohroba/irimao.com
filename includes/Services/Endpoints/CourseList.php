<?php

namespace IMAOCustom\Services\Endpoints;

use IMAOCustom\Helpers\CourseData;
use IMAOCustom\Helpers\UserMeta;
use WP_Query;

class CourseList {
    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ] );
        add_action( 'woocommerce_account_course-list_endpoint', [ $this, 'content' ] );
        add_shortcode( 'crm_courses_list', [ $this, 'shortcode' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'course-list', EP_ROOT | EP_PAGES );
    }

    public function menu_item( array $items ): array {
        $items['course-list'] = 'لیست دوره‌ها';
        return $items;
    }

    public function content(): void {
        echo $this->render();
    }

    public function shortcode(): string {
        return $this->render();
    }

    private function render(): string {
        $gender = '';
        if ( function_exists( 'get_current_user_id' ) ) {
            $uid = get_current_user_id();
            if ( $uid ) {
                $gender = strtolower( trim( UserMeta::get( $uid, 'gender', '' ) ) );
                if ( $gender === 'male' ) {
                    $gender = 'men';
                } elseif ( $gender === 'female' ) {
                    $gender = 'women';
                }
            }
        }
        if ( ! $gender ) {
            return '<p>برای مشاهدهٔ دوره‌ها ابتدا جنسیت خود را در بخش اطلاعات پایه ثبت کنید.</p>';
        }

        $today = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : date( 'Y-m-d' );
        $q     = new WP_Query([
            'post_type'      => 'course',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => 'registration_start',
                    'value'   => $today,
                    'compare' => '<=',
                    'type'    => 'DATE',
                ],
                [
                    'key'     => 'registration_end',
                    'value'   => $today,
                    'compare' => '>=',
                    'type'    => 'DATE',
                ],
            ],
            'tax_query'      => [
                [
                    'taxonomy' => 'gender',
                    'field'    => 'slug',
                    'terms'    => $gender,
                ],
            ],
        ]);
        if ( ! $q->have_posts() ) {
            return '<p>دوره‌ در حال ثبت نامی موجود نیست.</p>';
        }
        $cols = CourseData::columns();
        ob_start();
        ?>
        <div class="crm-course-wrap">
            <div class="crm-course-title">لیست دوره‌ها</div>
            <table class="crm-course-table striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>عنوان</th>
                        <?php foreach ( $cols as $label ) : ?>
                            <th><?php echo esc_html( $label ); ?></th>
                        <?php endforeach; ?>
                        <th>اقدام</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; while ( $q->have_posts() ) : $q->the_post(); $cid = get_the_ID(); ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php the_title(); ?></td>
                            <?php foreach ( $cols as $key => $label ) :
                                if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( $key ) ) {
                                    $terms = get_the_terms( $cid, $key );
                                    $val   = $terms && ! is_wp_error( $terms ) ? join( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
                                } else {
                                    $val = get_post_meta( $cid, $key, true );
                                }
                            ?>
                                <td><?php echo $val ? esc_html( $val ) : '—'; ?></td>
                            <?php endforeach; ?>
                            <td><a href="<?php echo esc_url( '/my-account/course-details/?course_id=' . $cid ); ?>">جزئیات / ثبت‌نام</a></td>
                        </tr>
                    <?php endwhile; wp_reset_postdata(); ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}
