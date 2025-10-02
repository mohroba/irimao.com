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
        $champion_map = SelfDeclarationData::champion_age_map();
        $age_category = $coursetype === 4 ? (int) ( $_POST['age_category'] ?? 0 ) : 0;
        $weight_class = $coursetype === 4 ? (int) ( $_POST['weight_class'] ?? 0 ) : 0;
        $competition_style = $coursetype === 4 ? sanitize_text_field( $_POST['competition_style'] ?? '' ) : '';
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
        if ( $coursetype === 4 ) {
            if ( ! $age_category || ! isset( $champion_map[ $age_category ] ) ) {
                $this->errors[] = 'رده سنی الزامی است.';
            }
            $weight_options = $age_category && isset( $champion_map[ $age_category ] ) ? $champion_map[ $age_category ]['weights'] : [];
            if ( ! $weight_class || ! isset( $weight_options[ $weight_class ] ) ) {
                $this->errors[] = 'کلاس وزنی الزامی است.';
            }
            if ( $competition_style === '' ) {
                $this->errors[] = 'استایل مسابقاتی الزامی است.';
            }
        } else {
            $age_category      = 0;
            $weight_class      = 0;
            $competition_style = '';
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
            update_post_meta( $post_id, 'age_category', $age_category );
            update_post_meta( $post_id, 'weight_class', $weight_class );
            update_post_meta( $post_id, 'competition_style', $competition_style );
            if ( $image_url ) {
                update_post_meta( $post_id, 'image_url', $image_url );
            }
        }
    }

    public function render(): string {
        $types       = SelfDeclarationData::course_types();
        $degree_opts = SelfDeclarationData::degree_options();
        $champion_map = SelfDeclarationData::champion_age_map();
        $branches    = class_exists( 'WC_Countries' ) ? ( new \WC_Countries() )->get_states( 'IR' ) : [];
        $selectedType   = isset( $_POST['coursetype'] ) ? (int) $_POST['coursetype'] : 0;
        $selectedDegree = $_POST['degree'] ?? '';
        $selectedAge    = isset( $_POST['age_category'] ) ? (int) $_POST['age_category'] : 0;
        $selectedWeight = isset( $_POST['weight_class'] ) ? (int) $_POST['weight_class'] : 0;
        $competitionStyle = $_POST['competition_style'] ?? '';
        if ( function_exists( 'sanitize_text_field' ) ) {
            $competitionStyle = sanitize_text_field( $competitionStyle );
        }

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
                    <div class="sd-field" id="age_category_field" style="<?= $selectedType === 4 ? '' : 'display:none;'; ?>">
                        <label>رده سنی<span style="color:#d00">*</span></label>
                        <select class="crm-select2" name="age_category" id="age_category" data-selected="<?= esc_attr( $selectedAge ?: '' ); ?>">
                            <option value="">— انتخاب کنید —</option>
                            <?php foreach ( $champion_map as $term_id => $meta ): ?>
                                <option value="<?= esc_attr( (string) $term_id ); ?>" <?= selected( (string) $selectedAge, (string) $term_id, false ); ?>><?= esc_html( $meta['label'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php $weight_options = $selectedAge && isset( $champion_map[ $selectedAge ] ) ? $champion_map[ $selectedAge ]['weights'] : []; ?>
                    <div class="sd-field" id="weight_class_field" style="<?= $selectedType === 4 ? '' : 'display:none;'; ?>">
                        <label>کلاس وزنی<span style="color:#d00">*</span></label>
                        <select class="crm-select2" name="weight_class" id="weight_class" data-selected="<?= esc_attr( $selectedWeight ?: '' ); ?>">
                            <option value="">— ابتدا رده سنی را انتخاب کنید —</option>
                            <?php foreach ( $weight_options as $term_id => $label ): ?>
                                <option value="<?= esc_attr( (string) $term_id ); ?>" <?= selected( (string) $selectedWeight, (string) $term_id, false ); ?>><?= esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sd-field" id="competition_style_field" style="<?= $selectedType === 4 ? '' : 'display:none;'; ?>">
                        <label>استایل مسابقاتی<span style="color:#d00">*</span></label>
                        <input type="text" name="competition_style" id="competition_style" value="<?= esc_attr( $competitionStyle ); ?>">
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
                .'<th>تاریخ اخذ</th><th>تاریخ آزمون</th><th>رده سنی</th><th>کلاس وزنی</th><th>استایل مسابقاتی</th><th>هیئت</th><th>وضعیت</th><th>اقدام</th>'
                .'</tr></thead><tbody>';
            $i = 1;
            while ( $q->have_posts() ) { $q->the_post();
                $pid = get_the_ID();
                $st  = get_post_status( $pid );
                $lbl = $st === 'publish' ? '<span style="color:green">تأیید شده</span>' : ( $st === 'pending' ? '<span style="color:orange">در حال بررسی</span>' : '<span style="color:red">عدم تأیید</span>' );
                $ct = (int) get_post_meta( $pid, 'coursetype', true );
                $dg = (string) get_post_meta( $pid, 'degree', true );
                $dgLabel = $degree_opts[ $ct ][ $dg ] ?? '—';
                $ageId = (int) get_post_meta( $pid, 'age_category', true );
                $weightId = (int) get_post_meta( $pid, 'weight_class', true );
                $style = (string) get_post_meta( $pid, 'competition_style', true );
                $term_error = function_exists( 'is_wp_error' ) ? 'is_wp_error' : null;
                $ageLabel = '—';
                if ( $ageId ) {
                    $ageTerm = get_term( $ageId, 'age_category' );
                    if ( ! ( $term_error && $term_error( $ageTerm ) ) && $ageTerm && isset( $ageTerm->name ) ) {
                        $ageLabel = $ageTerm->name;
                    }
                }
                $weightLabel = '—';
                if ( $weightId ) {
                    $weightTerm = get_term( $weightId, 'age_category' );
                    if ( ! ( $term_error && $term_error( $weightTerm ) ) && $weightTerm && isset( $weightTerm->name ) ) {
                        $weightLabel = $weightTerm->name;
                    }
                }
                $boardKey = (string) get_post_meta( $pid, 'boards', true );
                $out .= '<tr>'
                    .'<td>'.$i.'</td>'
                    .'<td>'.esc_html( $types[ $ct ] ?? '—' ).'</td>'
                    .'<td>'.esc_html( $dgLabel ).'</td>'
                    .'<td>'.esc_html( get_post_meta( $pid, 'hokm_number', true ) ).'</td>'
                    .'<td>'.esc_html( get_post_meta( $pid, 'getdate', true ) ).'</td>'
                    .'<td>'.esc_html( get_post_meta( $pid, 'exam_date', true ) ).'</td>'
                    .'<td>'.esc_html( $ageLabel ).'</td>'
                    .'<td>'.esc_html( $weightLabel ).'</td>'
                    .'<td>'.esc_html( $style ?: '—' ).'</td>'
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

