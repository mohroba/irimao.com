<?php

namespace IMAOCustom\Forms;

use IMAOCustom\Helpers\SelfDeclarationData;
use IMAOCustom\Services\Validation;
use WP_Query;

class SelfDeclarationForm extends BaseForm {
    protected string $nonce_action = 'imao_self_declaration';

    protected function fields(): array {
        return [];
    }

    protected function submit(): void {
        $user_id    = get_current_user_id();
        $coursetype = (int) ( $_POST['coursetype'] ?? 0 );
        $degree     = sanitize_text_field( $_POST['degree'] ?? '' );
        $hokm       = sanitize_text_field( $_POST['hokm_number'] ?? '' );
        $getdate    = $this->read_date( 'getdate' );
        $exam_date  = $this->read_date( 'exam_date' );
        $theory     = $this->read_date( 'theory_date' );
        $board      = sanitize_text_field( $_POST['boards'] ?? '' );

        if ( ! $coursetype ) {
            $this->errors[] = 'نوع حکم الزامی است.';
        }
        if ( ! $degree && ! empty( SelfDeclarationData::degree_options()[ $coursetype ] ) ) {
            $this->errors[] = 'درجه دوره الزامی است.';
        }
        if ( ! $hokm ) {
            $this->errors[] = 'شماره حکم الزامی است.';
        }
        if ( ! $getdate ) {
            $this->errors[] = 'تاریخ اخذ حکم الزامی است.';
        }
        if ( ! $exam_date ) {
            $this->errors[] = 'تاریخ دوره/آزمون الزامی است.';
        }

        $image_url = '';
        if ( ! empty( $_FILES['course_imageurl']['name'] ) ) {
            $file_error = Validation::file( $_FILES['course_imageurl'], [ 'image/jpeg', 'image/png', 'application/pdf' ], 2 * 1024 * 1024, '۲ مگابایت' );
            if ( $file_error ) {
                $this->errors[] = $file_error;
            } else {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                $upload = \wp_handle_upload( $_FILES['course_imageurl'], [ 'test_form' => false ] );
                if ( empty( $upload['error'] ) && ! empty( $upload['url'] ) ) {
                    $image_url = esc_url_raw( $upload['url'] );
                } else {
                    $this->errors[] = 'آپلود فایل با خطا مواجه شد.';
                }
            }
        } else {
            $this->errors[] = 'تصویر حکم الزامی است.';
        }

        if ( $this->errors ) {
            return;
        }

        $post_id = wp_insert_post([
            'post_type'   => 'self_declaration',
            'post_status' => 'pending',
            'post_author' => $user_id,
            'post_title'  => 'Self Declaration ' . $user_id . '-' . time(),
        ]);

        if ( $post_id ) {
            update_post_meta( $post_id, 'coursetype', $coursetype );
            update_post_meta( $post_id, 'degree', $degree );
            update_post_meta( $post_id, 'hokm_number', $hokm );
            update_post_meta( $post_id, 'getdate', $getdate );
            update_post_meta( $post_id, 'exam_date', $exam_date );
            update_post_meta( $post_id, 'theory_date', $theory );
            update_post_meta( $post_id, 'boards', $board );
            if ( $image_url ) {
                update_post_meta( $post_id, 'image_url', $image_url );
            }
        }
    }

    public function render(): string {
        $types       = SelfDeclarationData::course_types();
        $degree_opts = SelfDeclarationData::degree_options();
        $branches    = class_exists( 'WC_Countries' ) ? ( new \WC_Countries() )->get_states( 'IR' ) : [];
        $selectedType   = isset( $_POST['coursetype'] ) ? (int) $_POST['coursetype'] : 0;
        $selectedDegree = $_POST['degree'] ?? '';

        ob_start();
        ?>
        <div class="sd-container">
            <div class="sd-header">خوداظهاری</div>
            <?= $this->error_list(); ?>
            <form class="needs-swal" method="POST" enctype="multipart/form-data">
                <?php wp_nonce_field( $this->nonce_action, $this->nonce_name ); ?>
                <input type="hidden" name="sd_check" value="1">
                <div class="sd-grid">
                    <div class="sd-field">
                        <label>نوع حکم<span style="color:#d00">*</span></label>
                        <select class="crm-select2" id="coursetype" name="coursetype" required>
                            <option value="">— انتخاب کنید —</option>
                            <?php foreach ( $types as $v => $l ): ?>
                                <option value="<?= esc_attr( $v ); ?>" <?= selected( $selectedType, $v, false ); ?>><?= esc_html( $l ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sd-field" id="degree_field">
                        <label>درجه دوره<span style="color:#d00">*</span></label>
                        <select class="crm-select2" name="degree" id="degree" data-selected="<?= esc_attr( $selectedDegree ); ?>">
                            <option value="">— ابتدا نوع حکم را انتخاب کنید —</option>
                            <?php foreach ( $degree_opts[ $selectedType ] ?? [] as $val => $label ): ?>
                                <option value="<?= esc_attr( $val ); ?>" <?= selected( $selectedDegree, (string) $val, false ); ?>><?= esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sd-field">
                        <label>شماره حکم<span style="color:#d00">*</span></label>
                        <input type="text" name="hokm_number" value="<?= esc_attr( $_POST['hokm_number'] ?? '' ); ?>" required>
                    </div>
                    <div class="sd-field">
                        <label>تاریخ اخذ حکم<span style="color:#d00">*</span></label>
                        <?= $this->date_select( 'getdate', $this->read_date( 'getdate' ), true ); ?>
                    </div>
                    <div class="sd-field">
                        <label>تاریخ دوره/آزمون عملی<span style="color:#d00">*</span></label>
                        <?= $this->date_select( 'exam_date', $this->read_date( 'exam_date' ), true ); ?>
                        <div class="helper-note">در ثبت قهرمانی، تاریخ مسابقه نهایی ثبت شود.</div>
                    </div>
                    <div class="sd-field">
                        <label>تاریخ تئوری</label>
                        <?= $this->date_select( 'theory_date', $this->read_date( 'theory_date' ) ); ?>
                        <div class="helper-note">این فیلد فقط برای دوره های مربیگری تکمیل شود.</div>
                    </div>
                    <div class="sd-field">
                        <label>انتخاب تصویر حکم/مدرک<span style="color:#d00">*</span></label>
                        <input type="file" name="course_imageurl" accept=".jpg,.jpeg,.png,.pdf" required>
                        <div class="helper-note">حداکثر حجم 2MB. فرمت‌های jpg,jpeg,png,pdf.</div>
                    </div>
                    <div class="sd-field">
                        <label>هیئت</label>
                        <select class="crm-select2" name="boards">
                            <option value="">— انتخاب کنید —</option>
                            <?php foreach ( $branches as $c => $n ): ?>
                                <option value="<?= esc_attr( $c ); ?>" <?= selected( $_POST['boards'] ?? '', $c, false ); ?>><?= esc_html( $n ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <hr class="sd-divider">
                <div class="sd-submit"><button type="submit">ثبت و ارسال</button></div>
                <p class="sd-warning">- حداکثر حجم فایل تصویر حکم/مدرک نباید از 2 مگابایت بیشتر باشد.<br>- فایل‌هایی با پسوند jpg, jpeg, png و pdf قابل قبول هستند.</p>
                <p class="sd-disclaimer">در صورتی که تاریخ حکم شما قبل از ۱۳۹۹/۰۶/۰۱ بوده و فاقد QRCode می‌باشد، بعد از تأیید فدراسیون، برای ثبت و جدیدسازی احکام باید مبلغ ۳۷۰۰۰ تومان پرداخت گردد.</p>
            </form>
        </div>
        <?php
        $out = ob_get_clean();

        $user_id = get_current_user_id();
        $q = new WP_Query([
            'post_type'      => 'self_declaration',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_status'    => 'any',
        ]);
        if ( $q->have_posts() ) {
            $out .= '<div class="sd-container"><div class="sd-header" style="margin-bottom:20px">در خواست های خود اظهاری من</div>';
            $out .= '<div class="sd-table-responsive"><table class="shop_table striped" style="text-align:center"><thead><tr>'
                .'<th>#</th><th>نوع</th><th>درجه</th><th>شماره</th>'
                .'<th>تاریخ اخذ</th><th>تاریخ آزمون</th><th>هیئت</th><th>وضعیت</th><th>اقدام</th>'
                .'</tr></thead><tbody>';
            $i = 1;
            while ( $q->have_posts() ) { $q->the_post();
                $pid = get_the_ID();
                $st  = get_post_status( $pid );
                $lbl = $st === 'publish' ? '<span style="color:green">تأیید شده</span>' : ( $st === 'pending' ? '<span style="color:orange">در حال بررسی</span>' : '<span style="color:red">عدم تأیید</span>' );
                $ct = (int) get_post_meta( $pid, 'coursetype', true );
                $dg = (string) get_post_meta( $pid, 'degree', true );
                $dgLabel = $degree_opts[ $ct ][ $dg ] ?? '—';
                $boardKey = (string) get_post_meta( $pid, 'boards', true );
                $out .= '<tr>'
                    .'<td>'.$i.'</td>'
                    .'<td>'.esc_html( $types[ $ct ] ?? '—' ).'</td>'
                    .'<td>'.esc_html( $dgLabel ).'</td>'
                    .'<td>'.esc_html( get_post_meta( $pid, 'hokm_number', true ) ).'</td>'
                    .'<td>'.esc_html( get_post_meta( $pid, 'getdate', true ) ).'</td>'
                    .'<td>'.esc_html( get_post_meta( $pid, 'exam_date', true ) ).'</td>'
                    .'<td>'.esc_html( $branches[ $boardKey ] ?? '—' ).'</td>'
                    .'<td>'.$lbl.'</td>';
                $image_url = esc_url( get_post_meta( $pid, 'image_url', true ) );
                $out .= '<td>'.( $image_url ? '<a href="'.$image_url.'" target="_blank">🔍</a>' : '—' ).'</td></tr>';
                $i++;
            }
            wp_reset_postdata();
            $out .= '</tbody></table></div></div>';
        }
        return $out;
    }
}

