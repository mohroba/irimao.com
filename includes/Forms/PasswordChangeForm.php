<?php
namespace IMAOCustom\Forms;

class PasswordChangeForm extends BaseForm {
    protected string $nonce_action = 'imao_change_password';

    protected function fields(): array {
        $user = wp_get_current_user();
        return [
            'user_login' => $user->user_login,
        ];
    }

    protected function submit(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }
        $uid      = get_current_user_id();
        $current  = (string) ( $_POST['current_pass'] ?? '' );
        $new      = (string) ( $_POST['new_pass'] ?? '' );
        $confirm  = (string) ( $_POST['confirm_pass'] ?? '' );
        $user     = wp_get_current_user();

        if ( $current === '' || ! wp_check_password( $current, $user->user_pass, $uid ) ) {
            $this->errors[] = 'رمز عبور فعلی نادرست است.';
        }
        if ( $new === '' ) {
            $this->errors[] = 'رمز عبور جدید الزامی است.';
        }
        if ( $new !== $confirm ) {
            $this->errors[] = 'تکرار رمز عبور با رمز جدید مطابقت ندارد.';
        }
        if ( $this->errors ) {
            return;
        }
        wp_update_user( [ 'ID' => $uid, 'user_pass' => $new ] );
        if ( function_exists( 'wc_add_notice' ) ) {
            wc_add_notice( 'رمز عبور با موفقیت تغییر یافت.', 'success' );
        }
    }

    public function render(): string {
        if ( ! is_user_logged_in() ) {
            return '<p>لطفاً وارد شوید.</p>';
        }
        $f    = $this->fields();
        $html = '<div class="sd-container">';
        $html .= '<form method="post" class="needs-swal">';
        $html .= wp_nonce_field( $this->nonce_action, $this->nonce_name, true, false );
        $html .= $this->error_list();
        $html .= '<div class="cbif-grid" style="margin-bottom: 15px">';
        $html .= '<div class="cbif-field"><label for="user_login">نام کاربری</label><input type="text" id="user_login" value="' . esc_attr( $f['user_login'] ) . '" disabled></div>';
        $html .= '<div class="cbif-field"><label for="current_pass">رمز عبور فعلی<span class="required">*</span></label><input type="password" id="current_pass" name="current_pass"></div>';
        $html .= '<div class="cbif-field"><label for="new_pass">رمز عبور جدید<span class="required">*</span></label><input type="password" id="new_pass" name="new_pass"></div>';
        $html .= '<div class="cbif-field"><label for="confirm_pass">تکرار رمز عبور<span class="required">*</span></label><input type="password" id="confirm_pass" name="confirm_pass"></div>';
        $html .= '</div>';
        $html .= '<button type="submit" class="button">ذخیره</button>';
        $html .= '</form></div>';
        return $html;
    }
}
