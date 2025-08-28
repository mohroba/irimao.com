<?php

namespace IMAOCustom\Forms;

use WP_Query;

class StyleCommitteeForm extends BaseForm {
    protected string $nonce_action = 'imao_style_committe';

    protected function fields(): array {
        return [];
    }

    protected function submit(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }
        $user_id    = get_current_user_id();
        $full_name  = sanitize_text_field( $_POST['full_name'] ?? '' );
        $national   = sanitize_text_field( $_POST['national_id'] ?? '' );
        $committees = array_map( 'sanitize_text_field', (array) ( $_POST['committees'] ?? [] ) );

        if ( empty( $committees ) ) {
            $this->errors[] = 'انتخاب کمیته الزامی است.';
        }
        if ( $this->errors ) {
            return;
        }

        $post_id = wp_insert_post([
            'post_type'   => 'style_committe_request',
            'post_status' => 'pending',
            'post_author' => $user_id,
            'post_title'  => 'Style Committe Request ' . $user_id . '-' . time(),
        ]);

        if ( $post_id ) {
            update_post_meta( $post_id, 'full_name', $full_name );
            update_post_meta( $post_id, 'national_id', $national );
            update_post_meta( $post_id, 'committees', $committees );
            $this->saved = true;
        }
    }

    public function render(): string {
        if ( ! is_user_logged_in() ) {
            return '<p>لطفاً وارد شوید.</p>';
        }
        $uid       = get_current_user_id();
        $full_name = trim( get_user_meta( $uid, 'first_name_fa', true ) . ' ' . get_user_meta( $uid, 'last_name_fa', true ) );
        $national  = get_user_meta( $uid, 'national_id', true );
        $selected  = (array) ( $_POST['committees'] ?? [] );
        $options   = [
            'کمیته داوران',
            'کمیته فنی',
            'کمیته مسابقات',
            'کمیته حراست و انتظامات',
            'کمیته حقوقی و انظباطی',
            'کمیته مربیان',
            'کمیته روابط بین‌الملل',
            'کمیته دفاع شخصی و هنرهای فردی',
            'کمیته پیشکسوتان',
            'کمیته استعدادیابی و قهرمانی',
            'کمیته امور استان‌ها و روابط عمومی',
            'کمیته بازرسی و نظارت',
            'کمیته تحقیق و پژوهش',
            'کمیته فرهنگی',
            'کمیته همگانی',
            'کمیته انفورماتیک',
            'کمیته صدور احکام',
            'کمیته اجرایی',
            'کمیته تبلیغات و اسپانسرینگ',
        ];

        ob_start();
        ?>
        <div class="sd-container">
            <div class="sd-header">درخواست عضویت در کمیته‌های سبک</div>
            <?= $this->success_message(); ?>
            <?= $this->error_list(); ?>
            <form class="needs-swal" method="POST">
                <?php wp_nonce_field( $this->nonce_action, $this->nonce_name ); ?>
                <div class="sd-grid">
                    <div class="sd-field">
                        <label>نام و نام خانوادگی</label>
                        <input type="text" name="full_name" value="<?= esc_attr( $full_name ); ?>" readonly>
                    </div>
                    <div class="sd-field">
                        <label>کد ملی</label>
                        <input type="text" name="national_id" value="<?= esc_attr( $national ); ?>" readonly>
                    </div>
                    <div class="sd-field">
                        <label>کمیته مدنظر<span style="color:#d00">*</span></label>
                        <select class="crm-select2" name="committees[]" multiple required>
                            <?php foreach ( $options as $opt ): ?>
                                <option value="<?= esc_attr( $opt ); ?>" <?= in_array( $opt, $selected, true ) ? 'selected' : '' ?>><?= esc_html( $opt ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <hr class="sd-divider">
                <div class="sd-submit"><button type="submit">ارسال</button></div>
            </form>
        </div>
        <?php
        $out = ob_get_clean();

        $q = new WP_Query([
            'post_type'      => 'style_committe_request',
            'author'         => $uid,
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_status'    => 'any',
        ]);
        if ( $q->have_posts() ) {
            $out .= '<div class="sd-container"><div class="sd-header" style="margin-bottom:20px">درخواست‌های من</div>';
            $out .= '<div class="sd-table-responsive"><table class="shop_table striped" style="text-align:center"><thead><tr>'
                .'<th>#</th><th>کمیته‌ها</th><th>وضعیت</th><th>دلیل رد</th>'
                .'</tr></thead><tbody>';
            $i = 1;
            while ( $q->have_posts() ) { $q->the_post();
                $pid   = get_the_ID();
                $st    = get_post_status( $pid );
                $lbl   = $st === 'publish' ? '<span style="color:green">تأیید شده</span>' : ( $st === 'pending' ? '<span style="color:orange">در حال بررسی</span>' : '<span style="color:red">رد شده</span>' );
                $comms = (array) get_post_meta( $pid, 'committees', true );
                $reason = get_post_meta( $pid, 'stylecomm_rejection_reason', true );
                $out .= '<tr>'
                    .'<td>'.$i.'</td>'
                    .'<td>'.esc_html( implode( '، ', $comms ) ).'</td>'
                    .'<td>'.$lbl.'</td>'
                    .'<td>'.esc_html( $reason ?: '—' ).'</td>'
                    .'</tr>';
                $i++;
            }
            wp_reset_postdata();
            $out .= '</tbody></table></div></div>';
        }
        return $out;
    }
}

