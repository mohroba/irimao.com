<?php

namespace IMAOCustom\Forms;

use IMAOCustom\Helpers\SelfDeclarationData;
use WP_Query;

class SelfDeclarationsList {
    public function render(): string {
        if ( ! is_user_logged_in() ) {
            return '<p style="text-align:center;color:#c00;">لطفاً وارد شوید.</p>';
        }
        $user_id = get_current_user_id();
        $types   = SelfDeclarationData::course_types();
        $degrees = SelfDeclarationData::list_degrees();
        $view_map = SelfDeclarationData::view_map();

        $q = new WP_Query([
            'post_type'      => 'self_declaration',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        if ( ! $q->have_posts() ) {
            return '<p style="text-align:center;">هیچ درخواست خوداظهاری‌ ثبت نکرده‌اید.</p>';
        }
        ob_start();
        ?>
        <div class="sd-container"><div class="col-md-9">
        <div class="sd-header" style="margin-bottom:20px">لیست احکام ثبت شده</div>
        <table class="table table-striped table-hover table-responsive-sm custom-table rtl tbl-loader" style="width:100%;">
            <thead style="background:#ff92008f;color:#000;">
                <tr>
                    <th class="text-center" style="font-size:12px;text-align:center">ردیف</th>
                    <th class="text-center" style="font-size:12px;text-align:center">نوع حکم</th>
                    <th class="text-center" style="font-size:12px;text-align:center">درجه</th>
                    <th class="text-center" style="font-size:12px;text-align:center">تاریخ حکم</th>
                    <th class="text-center" style="font-size:12px;text-align:center">شماره حکم</th>
                    <th class="text-center" style="font-size:12px;text-align:center">نحوه ثبت حکم</th>
                    <th class="text-center" style="font-size:12px;text-align:center">اقدام</th>
                </tr>
            </thead>
            <tbody>
            <?php $i = 1; while ( $q->have_posts() ): $q->the_post();
                $pid    = get_the_ID();
                $type   = get_post_meta( $pid, 'coursetype', true );
                $deg    = get_post_meta( $pid, 'degree', true );
                $date   = get_post_meta( $pid, 'getdate', true );
                $num    = get_post_meta( $pid, 'hokm_number', true );
                $method = get_post_meta( $pid, 'registration_method', true ) ?: 'خوداظهاری';
                $view_base = $view_map[ $type ] ?? $view_map[1];
                $view_url  = esc_url( $view_base . $pid );
                $pay_url   = esc_url( "certificate-payment.php?certificateId={$pid}" );
            ?>
                <tr>
                    <td class="text-center" style="font-size:12px;text-align:center"><?php echo $i; ?></td>
                    <td class="text-center" style="font-size:12px;text-align:center"><?php echo esc_html( $types[ $type ] ?? '' ); ?></td>
                    <td class="text-center" style="font-size:12px;text-align:center"><?php echo esc_html( $degrees[ $deg ] ?? '' ); ?></td>
                    <td class="text-center" style="font-size:12px;text-align:center"><?php echo esc_html( $date ); ?></td>
                    <td class="text-center" style="font-size:12px;text-align:center"><?php echo esc_html( $num ); ?></td>
                    <td class="text-center" style="font-size:12px;text-align:center"><?php echo esc_html( $method ); ?></td>
                    <td class="text-center" style="font-size:12px;">
                        <a href="<?php echo $view_url; ?>" target="_blank" title="مشاهده حکم" style="margin-left:5px;"><i class="fas fa-award fa-lg text-danger"></i></a>
                        <a href="<?php echo $pay_url; ?>" title="درخواست صدور و ارسال حکم"><i class="fa fa-credit-card fa-lg fa-primary"></i></a>
                    </td>
                </tr>
            <?php $i++; endwhile; wp_reset_postdata(); ?>
            </tbody>
        </table>
        </div></div>
        <?php
        return ob_get_clean();
    }
}

