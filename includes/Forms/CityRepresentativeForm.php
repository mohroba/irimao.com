<?php

namespace IMAOCustom\Forms;

use IMAOCustom\Helpers\CityMap;
use IMAOCustom\Helpers\RepresentativeManager;
use WP_User;

class CityRepresentativeForm extends BaseForm {
    protected string $nonce_action = 'imao_city_representative';
    private RepresentativeManager $manager;
    private string $province_code;
    private array $cities;
    private bool $prefill_ready = false;

    public function __construct( RepresentativeManager $manager, string $province_code ) {
        $this->manager       = $manager;
        $this->province_code = $province_code;
        $this->cities        = CityMap::get_cities( $province_code );
        $this->success_text  = 'عملیات با موفقیت انجام شد.';
        $this->prefill_ready = isset( $_GET['city_rep_success'] );
        if ( $this->prefill_ready ) {
            $this->saved = true;
        }
        parent::__construct();
    }

    protected function fields(): array {
        return [];
    }

    protected function submit(): void {
        $action = sanitize_text_field( $_POST['imao_city_rep_action'] ?? 'assign' );
        if ( $action === 'deactivate' ) {
            $this->handle_deactivate();
        }
        if ( $action === 'delete' ) {
            $this->handle_delete();
        }
        if ( $action === 'assign' ) {
            $this->handle_assign();
        }

        if ( $this->saved && empty( $this->errors ) ) {
            $this->redirect_after_success( $action );
        }
    }

    private function handle_assign(): void {
        $national = preg_replace( '/\D+/', '', (string) ( $_POST['city_national_id'] ?? '' ) );
        $city     = sanitize_text_field( $_POST['city_name'] ?? '' );
        $gender   = sanitize_text_field( $_POST['city_gender'] ?? '' );
        if ( ! $national || ! $city ) {
            $this->errors[] = 'کد ملی و شهرستان الزامی هستند.';
            return;
        }
        if ( ! $gender ) {
            $this->errors[] = 'انتخاب جنسیت الزامی است.';
            return;
        }
        $user = $this->manager->find_user_by_national_id( $national );
        if ( ! $user instanceof WP_User ) {
            $this->errors[] = 'کاربری با این کد ملی یافت نشد.';
            return;
        }
        if ( ! in_array( $city, $this->cities, true ) ) {
            $this->errors[] = 'شهرستان انتخاب‌شده در محدوده استان شما نیست.';
            return;
        }
        $result = $this->manager->assign_city_representative( (int) $user->ID, $this->province_code, $city, get_current_user_id(), $gender );
        if ( $result['ok'] ?? false ) {
            $this->saved = true;
        } else {
            $this->errors[] = $result['message'] ?? 'خطا در ذخیره نماینده شهرستان.';
        }
    }

    private function handle_deactivate(): void {
        $assignment_id = isset( $_POST['assignment_id'] ) ? (int) $_POST['assignment_id'] : 0;
        $assignment    = $this->manager->get_city_assignment( $assignment_id );
        if ( ! $assignment ) {
            $this->errors[] = 'رکورد یافت نشد.';
            return;
        }
        if ( $assignment['province_code'] !== $this->province_code ) {
            $this->errors[] = 'امکان مدیریت این رکورد وجود ندارد.';
            return;
        }
        if ( $assignment['status'] !== 'active' ) {
            $this->errors[] = 'این رکورد از قبل غیرفعال شده است.';
            return;
        }
        $result = $this->manager->deactivate_city_assignment( $assignment_id, get_current_user_id() );
        if ( $result['ok'] ?? false ) {
            $this->saved = true;
        } else {
            $this->errors[] = $result['message'] ?? 'خطا در به‌روزرسانی وضعیت.';
        }
    }

    private function handle_delete(): void {
        $assignment_id = isset( $_POST['assignment_id'] ) ? (int) $_POST['assignment_id'] : 0;
        $assignment    = $this->manager->get_city_assignment( $assignment_id );
        if ( ! $assignment ) {
            $this->errors[] = 'رکورد یافت نشد.';
            return;
        }
        if ( $assignment['province_code'] !== $this->province_code ) {
            $this->errors[] = 'امکان مدیریت این رکورد وجود ندارد.';
            return;
        }
        $result = $this->manager->delete_city_assignment( $assignment_id );
        if ( $result['ok'] ?? false ) {
            $this->saved = true;
        } else {
            $this->errors[] = $result['message'] ?? 'خطا در حذف رکورد.';
        }
    }

    private function redirect_after_success( string $action ): void {
        if ( headers_sent() ) {
            return;
        }
        $url = remove_query_arg( [ 'city_rep_success' ] );
        $url = add_query_arg( [ 'city_rep_success' => $action ], $url );
        wp_safe_redirect( $url );
        exit;
    }

    public function render(): string {
        $assignments = $this->manager->get_city_assignments( $this->province_code );
        $genders     = \IMAOCustom\Helpers\RepresentativeManager::gender_labels();
        $province    = CityMap::get_provinces()[ $this->province_code ] ?? $this->province_code;
        $prefill_user = null;
        $prefill_name = '';
        if ( isset( $_POST['city_national_id'] ) ) {
            $maybe_user  = $this->manager->find_user_by_national_id( sanitize_text_field( (string) $_POST['city_national_id'] ) );
            if ( $maybe_user instanceof WP_User ) {
                $prefill_user = $maybe_user;
                $prefill_name = $maybe_user->display_name;
            }
        }
        ob_start();
        ?>
        <div class="sd-container">
            <div class="sd-header">ثبت نماینده شهرستان</div>
            <div class="sd-description">استان شما: <?= esc_html( $province ); ?></div>
            <?= $this->error_list(); ?>
            <?= $this->success_message(); ?>
            <div class="city-stepper">
                <div class="city-step <?= $prefill_user || $this->prefill_ready ? 'is-complete' : 'is-current'; ?>">
                    <span>۱</span>
                    <strong>جستجوی کاربر</strong>
                    <small>بر اساس کد ملی</small>
                </div>
                <div class="city-step <?= $prefill_user || $this->prefill_ready ? 'is-current' : 'is-disabled'; ?>">
                    <span>۲</span>
                    <strong>تعیین شهرستان و جنسیت</strong>
                    <small>پس از تأیید کاربر</small>
                </div>
            </div>
            <form method="post" class="needs-swal city-rep-form" id="city-rep-form">
                <?php wp_nonce_field( $this->nonce_action, $this->nonce_name ); ?>
                <input type="hidden" name="imao_city_rep_action" value="assign">
                <div class="sd-grid city-rep-grid">
                    <div class="sd-field">
                        <label>کد ملی کاربر<span class="required">*</span></label>
                        <div class="sd-inline">
                            <input type="text" name="city_national_id" id="city_national_id" value="<?= esc_attr( $_POST['city_national_id'] ?? '' ); ?>" required placeholder="مثال: 0012345678">
                            <button type="button" class="button city-check-btn" id="city_national_check" data-nonce="<?= esc_attr( wp_create_nonce( 'imao_city_rep_lookup' ) ); ?>">بررسی</button>
                        </div>
                        <small class="helper-note">پس از تأیید کد ملی، فیلدهای بعدی نمایش داده می‌شوند.</small>
                    </div>
                    <div class="sd-field city-step-details <?= $prefill_user || $this->prefill_ready ? 'is-visible' : 'is-hidden'; ?>">
                        <label>نام کاربر</label>
                        <input type="text" id="city_user_name" value="<?= esc_attr( $prefill_name ); ?>" readonly>
                        <input type="hidden" name="city_user_id" id="city_user_id" value="<?= $prefill_user ? esc_attr( (string) $prefill_user->ID ) : ''; ?>">
                    </div>
                    <div class="sd-field city-step-details <?= $prefill_user || $this->prefill_ready ? 'is-visible' : 'is-hidden'; ?>">
                        <label>شهرستان<span class="required">*</span></label>
                        <select name="city_name" id="city_name" class="crm-select2" required>
                            <option value="">— انتخاب کنید —</option>
                            <?php foreach ( $this->cities as $city ) : ?>
                                <option value="<?= esc_attr( $city ); ?>" <?= selected( $_POST['city_name'] ?? '', $city, false ); ?>><?= esc_html( $city ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sd-field city-step-details <?= $prefill_user || $this->prefill_ready ? 'is-visible' : 'is-hidden'; ?>">
                        <label>جنسیت<span class="required">*</span></label>
                        <select name="city_gender" id="city_gender" class="crm-select2" required>
                            <option value="">— انتخاب کنید —</option>
                            <?php foreach ( $genders as $g_key => $g_label ) : ?>
                                <option value="<?= esc_attr( $g_key ); ?>" <?= selected( $_POST['city_gender'] ?? '', $g_key, false ); ?>><?= esc_html( $g_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="sd-submit city-step-details <?= $prefill_user || $this->prefill_ready ? 'is-visible' : 'is-hidden'; ?>">
                    <button type="submit" class="btn sd-submit" id="city_rep_submit">ذخیره نماینده شهرستان</button>
                </div>
            </form>
            <div class="sd-header" style="margin-top:24px;">فهرست نمایندگان شهرستان</div>
            <div class="table-responsive">
                <table class="widefat striped city-rep-table">
                    <thead>
                        <tr>
                            <th>شهرستان</th>
                            <th>جنسیت</th>
                            <th>کاربر</th>
                            <th>وضعیت</th>
                            <th>تاریخ انتصاب</th>
                            <th>تاریخ لغو</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $assignments as $row ) :
                            $user = get_userdata( (int) $row->user_id );
                            ?>
                            <tr>
                                <td><?= esc_html( $row->city_name ); ?></td>
                                <td><?= esc_html( $genders[ $row->gender ] ?? '—' ); ?></td>
                                <td><?= $user instanceof WP_User ? esc_html( $user->display_name ) : '—'; ?></td>
                                <td><?= esc_html( $row->status === 'active' ? 'فعال' : 'غیرفعال' ); ?></td>
                                <td><?= esc_html( $row->assigned_at ); ?></td>
                                <td><?= esc_html( $row->deactivated_at ?: '—' ); ?></td>
                                <td>
                                    <?php if ( $row->status === 'active' ) : ?>
                                        <form method="post" class="inline-form needs-swal">
                                            <?php wp_nonce_field( $this->nonce_action, $this->nonce_name ); ?>
                                            <input type="hidden" name="imao_city_rep_action" value="deactivate">
                                            <input type="hidden" name="assignment_id" value="<?= esc_attr( (string) $row->id ); ?>">
                                            <button type="submit" class="button button-secondary button-small">لغو انتصاب</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
