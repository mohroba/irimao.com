<?php
namespace IMAOCustom\Forms;

use IMAOCustom\Helpers\UserMeta;
use IMAOCustom\Services\Validation;

class BasicInfoForm extends BaseForm {
    protected string $nonce_action = 'imao_basic_info';

    public function fields(): array {
        $user_id = get_current_user_id();
        return [
            'first_name'  => UserMeta::get( $user_id, 'first_name_fa' ),
            'last_name'   => UserMeta::get( $user_id, 'last_name_fa' ),
            'national_id' => UserMeta::get( $user_id, 'national_id' ),
        ];
    }

    protected function submit(): void {
        $user_id     = get_current_user_id();
        $first_name  = sanitize_text_field( $_POST['first_name'] ?? '' );
        $last_name   = sanitize_text_field( $_POST['last_name'] ?? '' );
        $national_id = sanitize_text_field( $_POST['national_id'] ?? '' );

        $err = Validation::required( $first_name, 'نام' );
        if ( $err ) { $this->errors[] = $err; }
        $err = Validation::required( $last_name, 'نام خانوادگی' );
        if ( $err ) { $this->errors[] = $err; }
        $err = Validation::national_id( $national_id );
        if ( $err ) { $this->errors[] = $err; }

        if ( $this->errors ) {
            return;
        }

        UserMeta::set( $user_id, 'first_name_fa', $first_name );
        UserMeta::set( $user_id, 'last_name_fa', $last_name );
        UserMeta::set( $user_id, 'national_id', $national_id );
    }

    public function render(): string {
        $fields = $this->fields();
        $html  = '<form method="post">';
        $html .= wp_nonce_field( $this->nonce_action, $this->nonce_name, true, false );
        $html .= $this->error_list();
        $html .= '<p><label>نام: <input type="text" name="first_name" value="' . esc_attr( $fields['first_name'] ) . '"></label></p>';
        $html .= '<p><label>نام خانوادگی: <input type="text" name="last_name" value="' . esc_attr( $fields['last_name'] ) . '"></label></p>';
        $html .= '<p><label>کد ملی: <input type="text" name="national_id" value="' . esc_attr( $fields['national_id'] ) . '"></label></p>';
        $html .= '<p><button type="submit">ذخیره</button></p>';
        $html .= '</form>';
        return $html;
    }
}
