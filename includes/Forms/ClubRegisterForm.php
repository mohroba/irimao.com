<?php
namespace IMAOCustom\Forms;

use IMAOCustom\Helpers\CityMap;
use IMAOCustom\Services\Validation;

class ClubRegisterForm extends BaseForm {
    protected string $nonce_action = 'imao_club_register';
    private ?\WP_Post $app = null;

    protected function fields(): array {
        if ( ! is_user_logged_in() ) {
            return [];
        }
        $user_id = get_current_user_id();
        $apps = get_posts([
            'post_type'   => 'club_application',
            'author'      => $user_id,
            'numberposts' => 1,
            'post_status' => [ 'pending','publish','draft' ],
        ]);
        $this->app = $apps ? $apps[0] : null;
        return [
            'club_name'     => $this->app ? $this->app->post_title : '',
            'club_owner'    => $this->meta('owner_name'),
            'club_postal'   => $this->meta('club_postal'),
            'club_province' => $this->meta('club_province'),
            'club_city'     => $this->meta('club_city'),
            'club_address'  => $this->meta('club_address'),
            'license_image' => $this->meta('license_image'),
            'status'        => $this->app ? get_post_status( $this->app ) : '',
        ];
    }

    private function meta( string $key ): string {
        return $this->app ? (string) get_post_meta( $this->app->ID, $key, true ) : '';
    }

    protected function submit(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }
        $locked = get_posts([
            'post_type'   => 'club_application',
            'author'      => get_current_user_id(),
            'numberposts' => 1,
            'post_status' => [ 'pending','publish' ],
        ]);
        if ( $locked ) {
            return;
        }
        $data = [
            'club_name'     => sanitize_text_field( $_POST['club_name'] ?? '' ),
            'club_owner'    => sanitize_text_field( $_POST['club_owner'] ?? '' ),
            'club_postal'   => sanitize_text_field( $_POST['club_postal'] ?? '' ),
            'club_province' => sanitize_text_field( $_POST['club_province'] ?? '' ),
            'club_city'     => sanitize_text_field( $_POST['club_city'] ?? '' ),
            'club_address'  => sanitize_textarea_field( $_POST['club_address'] ?? '' ),
        ];
        $required = [
            'club_name'     => 'نام باشگاه',
            'club_owner'    => 'صاحب امتیاز',
            'club_province' => 'استان',
            'club_city'     => 'شهر',
            'club_address'  => 'آدرس باشگاه',
        ];
        foreach ( $required as $k => $label ) {
            if ( $msg = Validation::required( $data[ $k ], $label ) ) {
                $this->errors[] = $msg;
            }
        }
        if ( $msg = Validation::postal_code( $data['club_postal'] ) ) {
            $this->errors[] = $msg;
        }
        $provinces = class_exists( '\\WC_Countries' ) ? ( new \WC_Countries() )->get_states( 'IR' ) : [];
        if ( $data['club_province'] && ! array_key_exists( $data['club_province'], $provinces ) ) {
            $this->errors[] = 'استان انتخاب شده نامعتبر است.';
        }
        $cities = CityMap::get_cities( $data['club_province'] );
        if ( $data['club_city'] && ! in_array( $data['club_city'], $cities, true ) ) {
            $this->errors[] = 'شهر انتخاب شده نامعتبر است.';
        }
        $image_url = '';
        if ( empty( $_FILES['club_license_image']['name'] ) ) {
            $this->errors[] = 'آپلود تصویر مجوز الزامی است.';
        } else {
            $file_error = Validation::file( $_FILES['club_license_image'], [ 'image/jpeg', 'image/png' ], 2 * 1024 * 1024, '۲ مگابایت' );
            if ( $file_error ) {
                $this->errors[] = $file_error;
            } else {
                $up = wp_handle_upload( $_FILES['club_license_image'], [ 'test_form' => false ] );
                if ( empty( $up['error'] ) && ! empty( $up['url'] ) ) {
                    $image_url = esc_url_raw( $up['url'] );
                } else {
                    $this->errors[] = 'آپلود تصویر با خطا مواجه شد.';
                }
            }
        }
        if ( $this->errors ) {
            return;
        }
        $pid = wp_insert_post([
            'post_type'   => 'club_application',
            'post_status' => 'pending',
            'post_author' => get_current_user_id(),
            'post_title'  => $data['club_name'],
        ]);
        if ( ! $pid ) {
            $this->errors[] = 'خطا در ذخیرهٔ درخواست.';
            return;
        }
        update_post_meta( $pid, 'owner_name',    $data['club_owner'] );
        update_post_meta( $pid, 'club_postal',   $data['club_postal'] );
        update_post_meta( $pid, 'club_province', $data['club_province'] );
        update_post_meta( $pid, 'club_city',     $data['club_city'] );
        update_post_meta( $pid, 'club_address',  $data['club_address'] );
        if ( $image_url ) {
            update_post_meta( $pid, 'license_image', $image_url );
        }
        wp_safe_redirect( wc_get_account_endpoint_url( 'club-register' ) );
        exit;
    }

    public function render(): string {
        if ( ! is_user_logged_in() ) {
            return '<p style="text-align:center;color:#c00;">لطفاً ابتدا وارد شوید.</p>';
        }
        $f       = $this->fields();
        $status  = $f['status'] ?? '';
        $locked  = in_array( $status, [ 'pending','publish' ], true );
        $provinces = class_exists( '\\WC_Countries' ) ? ( new \WC_Countries() )->get_states( 'IR' ) : [];
        $cities    = CityMap::get_cities( (string) $f['club_province'] );
        ob_start();
        if ( $locked ) {
            echo $status === 'pending' ? '<div class="notice-warning">درخواست شما در حال بررسی است.</div>' : '<div class="notice-success">درخواست شما تأیید شده است.</div>';
        } elseif ( $status === 'draft' ) {
            $reason = esc_html( get_post_meta( $this->app->ID ?? 0, 'rejection_reason', true ) );
            echo '<div class="notice-error">درخواست شما رد شد. دلیل: ' . $reason . '</div>';
        }
        ?>
        <div class="club-form-container">
            <?= $this->error_list(); ?>
            <form method="post" enctype="multipart/form-data" id="club-form">
                <?php wp_nonce_field( $this->nonce_action, $this->nonce_name ); ?>
                <div class="cf-grid">
                    <div class="cf-field">
                        <label>نام باشگاه <span style="color:#d00">*</span></label>
                        <input type="text" name="club_name" value="<?= esc_attr( $f['club_name'] ); ?>" <?= $locked ? 'disabled' : '' ?> required>
                    </div>
                    <div class="cf-field">
                        <label>صاحب امتیاز <span style="color:#d00">*</span></label>
                        <input type="text" name="club_owner" value="<?= esc_attr( $f['club_owner'] ); ?>" <?= $locked ? 'disabled' : '' ?> required>
                    </div>
                    <div class="cf-field">
                        <label>کد پستی</label>
                        <input type="text" name="club_postal" pattern="[0-9]{10}" value="<?= esc_attr( $f['club_postal'] ); ?>" <?= $locked ? 'disabled' : '' ?> >
                    </div>
                    <div class="cf-field">
                        <label>استان <span style="color:#d00">*</span></label>
                        <select name="club_province" id="club_province" class="crm-select2" <?= $locked ? 'disabled' : '' ?> required>
                            <option value="">— انتخاب کنید —</option>
                            <?php foreach ( $provinces as $code => $name ): ?>
                                <option value="<?= esc_attr( $code ); ?>" <?= selected( $f['club_province'], $code, false ); ?>><?= esc_html( $name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="cf-field">
                        <label>شهر <span style="color:#d00">*</span></label>
                        <select name="club_city" id="club_city" class="crm-select2" <?= $locked ? 'disabled' : '' ?> required>
                            <?php if ( $locked || ! empty( $f['club_city'] ) ): ?>
                                <option><?= esc_html( $f['club_city'] ?: '— انتخاب کنید —' ); ?></option>
                            <?php else: ?>
                                <option value="">— ابتدا استان را انتخاب کنید —</option>
                            <?php endif; ?>
                            <?php foreach ( $cities as $city ): ?>
                                <option value="<?= esc_attr( $city ); ?>" <?= selected( $f['club_city'], $city, false ); ?>><?= esc_html( $city ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="cf-field cf-wide">
                        <label>آدرس باشگاه <span style="color:#d00">*</span></label>
                        <textarea name="club_address" rows="3" <?= $locked ? 'disabled' : '' ?> required><?= esc_textarea( $f['club_address'] ); ?></textarea>
                    </div>
                    <div class="cf-field cf-wide">
                        <label>تصویر مجوز <span style="color:#d00">*</span></label>
                        <?php if ( $locked && $f['license_image'] ): ?>
                            <img src="<?= esc_url( $f['license_image'] ); ?>" class="thumb-lic" alt="">
                        <?php else: ?>
                            <input type="file" name="club_license_image" <?= $locked ? 'disabled' : '' ?> accept="image/*" required>
                            <?php if ( $f['license_image'] ): ?>
                                <img src="<?= esc_url( $f['license_image'] ); ?>" class="thumb-lic" alt="">
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php if ( ! $locked ): ?>
                    <div class="cf-submit">
                        <button type="submit" name="club_apply">ارسال برای بررسی</button>
                    </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}
