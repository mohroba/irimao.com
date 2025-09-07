<?php

namespace IMAOCustom\Services\Endpoints;

use IMAOCustom\Helpers\CourseData;
use IMAOCustom\Helpers\Date;
use IMAOCustom\Helpers\AgeCategory;
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
        $gender   = '';
        $age_slug = '';
        if ( function_exists( 'get_current_user_id' ) ) {
            $uid = get_current_user_id();
            if ( $uid ) {
                $gender = UserMeta::gender_slug( $uid );
                $birth  = UserMeta::get( $uid, 'birth_date', '' );
                if ( $birth ) {
                    $age = Date::age( $birth );
                    if ( $age !== null ) {
                        $age_slug = AgeCategory::slug_from_age( $age );
                    }
                }
            }
        }
        if ( ! $gender ) {
            return '<p>برای مشاهدهٔ دوره‌ها ابتدا جنسیت خود را در بخش اطلاعات پایه ثبت کنید.</p>';
        }
        if ( ! $age_slug ) {
            return '<p>برای مشاهدهٔ دوره‌ها ابتدا تاریخ تولد خود را در بخش اطلاعات پایه ثبت کنید.</p>';
        }

        $q = new WP_Query([
            'post_type'      => 'course',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => [
                [
                    'taxonomy' => 'gender',
                    'field'    => 'slug',
                    'terms'    => $gender,
                ],
                [
                    'taxonomy' => 'age_category',
                    'field'    => 'slug',
                    'terms'    => $age_slug,
                ],
            ],
        ]);
        $posts = [];
        while ( $q->have_posts() ) {
            $q->the_post();
            $cid = get_the_ID();
            $start = get_post_meta( $cid, 'registration_start', true );
            $end   = get_post_meta( $cid, 'registration_end', true );
            if ( Date::is_between( $start, $end ) ) {
                $posts[] = get_post();
            }
        }
        wp_reset_postdata();
        if ( ! $posts ) {
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
                    <?php $i = 1; foreach ( $posts as $post ) : $cid = $post->ID; ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo esc_html( get_the_title( $cid ) ); ?></td>
                            <?php foreach ( $cols as $key => $label ) :
                                if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( $key ) ) {
                                    $terms = get_the_terms( $cid, $key );
                                    if ( ! is_wp_error( $terms ) && $terms ) {
                                        if ( $key === 'age_category' ) {
                                            $terms = array_filter( $terms, static fn( $t ) => (int) $t->parent === 0 );
                                        }
                                        $val = join( ', ', wp_list_pluck( $terms, 'name' ) );
                                    } else {
                                        $val = '';
                                    }
                                } else {
                                    $val = get_post_meta( $cid, $key, true );
                                }
                            ?>
                                <td><?php echo $val ? esc_html( $val ) : '—'; ?></td>
                            <?php endforeach; ?>
                            <td><a href="<?php echo esc_url( '/my-account/course-details/?course_id=' . $cid ); ?>">جزئیات / ثبت‌نام</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}
