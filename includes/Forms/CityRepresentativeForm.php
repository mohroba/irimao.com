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

    public function __construct( RepresentativeManager $manager, string $province_code ) {
        $this->manager       = $manager;
        $this->province_code = $province_code;
        $this->cities        = CityMap::get_cities( $province_code );
        parent::__construct();
    }

    protected function fields(): array {
        return [];
    }

    protected function submit(): void {
        $action = sanitize_text_field( $_POST['imao_city_rep_action'] ?? 'assign' );
        if ( $action === 'deactivate' ) {
            $this->handle_deactivate();
            return;
        }
        $this->handle_assign();
    }

    private function handle_assign(): void {
        $user_id = isset( $_POST['city_user'] ) ? (int) $_POST['city_user'] : 0;
        $city    = sanitize_text_field( $_POST['city_name'] ?? '' );
        if ( ! $user_id || ! $city ) {
            $this->errors[] = 'انتخاب کاربر و شهرستان الزامی است.';
            return;
        }
        if ( ! in_array( $city, $this->cities, true ) ) {
            $this->errors[] = 'شهرستان انتخاب‌شده در محدوده استان شما نیست.';
            return;
        }
        $result = $this->manager->assign_city_representative( $user_id, $this->province_code, $city, get_current_user_id() );
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

    public function render(): string {
        $assignments = $this->manager->get_city_assignments( $this->province_code );
        $province    = CityMap::get_provinces()[ $this->province_code ] ?? $this->province_code;
        $users       = get_users( [ 'orderby' => 'display_name' ] );
        ob_start();
        ?>
        <div class="sd-container">
            <div class="sd-header">ثبت نماینده شهرستان</div>
            <div class="sd-description">استان شما: <?= esc_html( $province ); ?></div>
            <?= $this->error_list(); ?>
            <?= $this->success_message(); ?>
            <form method="post" class="needs-swal">
                <?php wp_nonce_field( $this->nonce_action, $this->nonce_name ); ?>
                <input type="hidden" name="imao_city_rep_action" value="assign">
                <div class="sd-grid">
                    <div class="sd-field">
                        <label>شهرستان<span style="color:#d00">*</span></label>
                        <select name="city_name" class="crm-select2" required>
                            <option value="">— انتخاب کنید —</option>
                            <?php foreach ( $this->cities as $city ) : ?>
                                <option value="<?= esc_attr( $city ); ?>" <?= selected( $_POST['city_name'] ?? '', $city, false ); ?>><?= esc_html( $city ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sd-field">
                        <label>کاربر<span style="color:#d00">*</span></label>
                        <select name="city_user" class="crm-select2" required>
                            <option value="">— انتخاب کنید —</option>
                            <?php foreach ( $users as $user ) : ?>
                                <option value="<?= esc_attr( (string) $user->ID ); ?>" <?= selected( (int) ( $_POST['city_user'] ?? 0 ), $user->ID, false ); ?>><?= esc_html( $user->display_name . ' (#' . $user->ID . ')' ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn sd-submit">ذخیره نماینده شهرستان</button>
            </form>
            <div class="sd-header" style="margin-top:24px;">فهرست نمایندگان شهرستان</div>
            <div class="table-responsive">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>شهرستان</th>
                            <th>کاربر</th>
                            <th>وضعیت</th>
                            <th>تاریخ انتصاب</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $assignments as $row ) :
                            $user = get_userdata( (int) $row->user_id );
                            ?>
                            <tr>
                                <td><?= esc_html( $row->city_name ); ?></td>
                                <td><?= $user instanceof WP_User ? esc_html( $user->display_name ) : '—'; ?></td>
                                <td><?= esc_html( $row->status === 'active' ? 'فعال' : 'غیرفعال' ); ?></td>
                                <td><?= esc_html( $row->assigned_at ); ?></td>
                                <td>
                                    <?php if ( $row->status === 'active' ) : ?>
                                        <form method="post" class="inline-form">
                                            <?php wp_nonce_field( $this->nonce_action, $this->nonce_name ); ?>
                                            <input type="hidden" name="imao_city_rep_action" value="deactivate">
                                            <input type="hidden" name="assignment_id" value="<?= esc_attr( (string) $row->id ); ?>">
                                            <button type="submit" class="button">لغو انتصاب</button>
                                        </form>
                                    <?php else : ?>
                                        —
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
