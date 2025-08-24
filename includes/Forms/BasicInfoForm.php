<?php
namespace IMAOCustom\Forms;

use IMAOCustom\Helpers\CityMap;
use IMAOCustom\Helpers\UserMeta;
use IMAOCustom\Services\Validation;

class BasicInfoForm extends BaseForm {
    protected string $nonce_action = 'imao_basic_info';

    /**
     * Meta keys handled by this form.
     *
     * @return string[]
     */
    private function meta_keys(): array {
        return [
            'billing_phone', 'national_id', 'gender', 'first_name_fa', 'last_name_fa',
            'first_name_en', 'last_name_en', 'father_name', 'birth_date', 'birth_province',
            'birth_city', 'marital_status', 'education_status', 'military_status',
            'residence_province', 'residence_city', 'postal_code', 'residence_address',
            'iban', 'card_number', 'coach_id', 'club_id',
        ];
    }

    /**
     * Fetch form field values for current user.
     */
    public function fields(): array {
        $uid   = get_current_user_id();
        $user  = wp_get_current_user();
        $fields = UserMeta::get_many( $uid, $this->meta_keys() );
        $fields['billing_email'] = $user->user_email;
        return $fields;
    }

    /**
     * Handle form submission with validation.
     */
    protected function submit(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }
        $uid  = get_current_user_id();
        $data = [];
        $all_keys = array_merge( $this->meta_keys(), [ 'billing_email' ] );
        foreach ( $all_keys as $key ) {
            if ( $key === 'residence_address' ) {
                $data[ $key ] = sanitize_textarea_field( $_POST[ $key ] ?? '' );
            } elseif ( $key === 'billing_email' ) {
                $data[ $key ] = sanitize_email( $_POST[ $key ] ?? '' );
            } else {
                $data[ $key ] = sanitize_text_field( $_POST[ $key ] ?? '' );
            }
        }

        $gender    = $data['gender'] ?? '';
        $required  = [
            'billing_phone','national_id','first_name_fa','last_name_fa','gender','father_name',
            'birth_date','birth_province','birth_city','marital_status','education_status',
            'residence_province','residence_city','residence_address',
        ];
        if ( $gender === 'male' ) {
            $required[] = 'military_status';
        }
        $labels = [
            'billing_phone'      => 'موبایل',
            'national_id'        => 'کد ملی',
            'first_name_fa'      => 'نام',
            'last_name_fa'       => 'نام خانوادگی',
            'gender'             => 'جنسیت',
            'father_name'        => 'نام پدر',
            'birth_date'         => 'تاریخ تولد',
            'birth_province'     => 'استان تولد',
            'birth_city'         => 'شهر تولد',
            'marital_status'     => 'وضعیت تأهل',
            'education_status'   => 'وضعیت تحصیلی',
            'military_status'    => 'وضعیت خدمت',
            'residence_province' => 'استان سکونت',
            'residence_city'     => 'شهر سکونت',
            'residence_address'  => 'آدرس',
        ];

        foreach ( $required as $key ) {
            if ( $data[ $key ] === '' ) {
                $this->errors[] = sprintf( '%s الزامی است.', $labels[ $key ] ?? $key );
            }
        }
        if ( $err = Validation::national_id( $data['national_id'] ) ) {
            $this->errors[] = $err;
        }
        if ( ! empty( $data['iban'] ) && ! preg_match( '/^[0-9]{24}$/', $data['iban'] ) ) {
            $this->errors[] = 'شماره شبا نامعتبر است.';
        }
        if ( ! empty( $data['card_number'] ) && ! preg_match( '/^[0-9]{16}$/', $data['card_number'] ) ) {
            $this->errors[] = 'شماره کارت نامعتبر است.';
        }
        if ( ! empty( $data['billing_email'] ) && ! is_email( $data['billing_email'] ) ) {
            $this->errors[] = 'ایمیل نامعتبر است.';
        }
        if ( $this->errors ) {
            return;
        }

        if ( $data['billing_email'] !== '' ) {
            wp_update_user( [ 'ID' => $uid, 'user_email' => $data['billing_email'] ] );
            update_user_meta( $uid, 'billing_email', $data['billing_email'] );
        }
        unset( $data['billing_email'] );
        foreach ( $this->meta_keys() as $k ) {
            update_user_meta( $uid, $k, $data[ $k ] ?? '' );
        }
    }

    /**
     * Utility to mark selected options.
     */
    private function sel( $cur, $val ): string {
        return $cur === $val ? ' selected' : '';
    }

    /**
     * Coaches approved for selection.
     */
    private function coach_options(): array {
        $users = get_users( [
            'role'       => 'coach',
            'meta_key'   => 'identity_verified_professional',
            'meta_value' => 'approved',
            'fields'     => [ 'ID', 'display_name' ],
        ] );
        $out = [ '' => '— انتخاب مربی —' ];
        foreach ( $users as $u ) {
            $out[ $u->ID ] = $u->display_name;
        }
        return $out;
    }

    /**
     * Clubs for selection.
     */
    private function club_options(): array {
        $users = get_users( [
            'role'     => 'club',
            'meta_key' => 'club_name',
            'orderby'  => 'meta_value',
            'order'    => 'ASC',
            'fields'   => [ 'ID' ],
        ] );
        $out = [ '' => '— انتخاب باشگاه —' ];
        foreach ( $users as $u ) {
            $out[ $u->ID ] = get_user_meta( $u->ID, 'club_name', true );
        }
        return $out;
    }

    public function render(): string {
        if ( ! is_user_logged_in() ) {
            return '<p>لطفاً وارد شوید.</p>';
        }
        $f         = $this->fields();
        $provinces = class_exists( '\\WC_Countries' ) ? ( new \WC_Countries() )->get_states( 'IR' ) : [];
        $birth     = CityMap::get_cities( (string) $f['birth_province'] );
        $res       = CityMap::get_cities( (string) $f['residence_province'] );
        $coaches   = $this->coach_options();
        $clubs     = $this->club_options();

        $html  = '<div class="sd-container container">';
        $html .= '<form method="post" id="id-form" class="needs-swal">';
        $html .= wp_nonce_field( $this->nonce_action, $this->nonce_name, true, false );
        $html .= $this->error_list();
        $html .= '<div class="row g-3">';

        // national id & gender
        $html .= '<div class="cbif-field col-md-6"><label for="national_id_display" class="form-label">کد ملی<span class="required text-danger">*</span></label>';
        $html .= '<input type="text" id="national_id_display" class="form-control" value="' . esc_attr( $f['national_id'] ) . '" disabled>';
        $html .= '<input type="hidden" id="national_id" name="national_id" value="' . esc_attr( $f['national_id'] ) . '"></div>';
        $html .= '<div class="cbif-field col-md-6"><label for="gender" class="form-label">جنسیت<span class="required text-danger">*</span></label><select id="gender" name="gender" class="crm-select2 form-select"><option value="">— انتخاب کنید —</option><option value="male"' . $this->sel( $f['gender'], 'male' ) . '>مرد</option><option value="female"' . $this->sel( $f['gender'], 'female' ) . '>زن</option></select></div>';

        // names
        $html .= '<div class="cbif-field col-md-6"><label for="first_name_fa" class="form-label">نام (فارسی)<span class="required text-danger">*</span></label><input type="text" id="first_name_fa" name="first_name_fa" class="form-control" value="' . esc_attr( $f['first_name_fa'] ) . '"></div>';
        $html .= '<div class="cbif-field col-md-6"><label for="last_name_fa" class="form-label">نام خانوادگی (فارسی)<span class="required text-danger">*</span></label><input type="text" id="last_name_fa" name="last_name_fa" class="form-control" value="' . esc_attr( $f['last_name_fa'] ) . '"></div>';
        $html .= '<div class="cbif-field col-md-6"><label for="first_name_en" class="form-label">نام (En)</label><input type="text" id="first_name_en" name="first_name_en" class="form-control" value="' . esc_attr( $f['first_name_en'] ) . '"></div>';
        $html .= '<div class="cbif-field col-md-6"><label for="last_name_en" class="form-label">نام‌خانوادگی (En)</label><input type="text" id="last_name_en" name="last_name_en" class="form-control" value="' . esc_attr( $f['last_name_en'] ) . '"></div>';

        // father, birth
        $html .= '<div class="cbif-field col-md-6"><label for="father_name" class="form-label">نام پدر<span class="required text-danger">*</span></label><input type="text" id="father_name" name="father_name" class="form-control" value="' . esc_attr( $f['father_name'] ) . '"></div>';
        $html .= '<div class="cbif-field col-md-6"><label for="birth_date" class="form-label">تاریخ تولد<span class="required text-danger">*</span></label><input type="text" id="birth_date" name="birth_date" class="form-control persian-date" data-jdp data-jdp-only-date value="' . esc_attr( $f['birth_date'] ) . '"></div>';
        $html .= '<div class="cbif-field col-md-6"><label class="form-label">استان/شهر تولد<span class="required text-danger">*</span></label><select id="birth_province" name="birth_province" class="crm-select2 form-select"><option value="">— انتخاب کنید —</option>';
        foreach ( $provinces as $code => $name ) {
            $html .= '<option value="' . esc_attr( $code ) . '"' . $this->sel( $f['birth_province'], $code ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select>';
        $html .= '<select id="birth_city" name="birth_city" class="crm-select2 form-select mt-2"' . ( empty( $birth ) ? ' disabled' : '' ) . '><option value="">' . ( empty( $birth ) ? '— ابتدا استان را انتخاب کنید —' : '— انتخاب کنید —' ) . '</option>';
        foreach ( $birth as $city ) {
            $html .= '<option value="' . esc_attr( $city ) . '"' . $this->sel( $f['birth_city'], $city ) . '>' . esc_html( $city ) . '</option>';
        }
        $html .= '</select></div>';

        // marital, education, military
        $html .= '<div class="cbif-field col-md-6"><label for="marital_status" class="form-label">وضعیت تأهل<span class="required text-danger">*</span></label><select id="marital_status" name="marital_status" class="crm-select2 form-select"><option value="">— انتخاب کنید —</option><option value="single"' . $this->sel( $f['marital_status'], 'single' ) . '>مجرد</option><option value="married"' . $this->sel( $f['marital_status'], 'married' ) . '>متأهل</option></select></div>';
        $edu = [
            '' => '— انتخاب کنید —',
            'student_primary'    => 'محصل - ابتدایی',
            'student_highschool' => 'محصل - دبیرستان',
            'student_college'    => 'دانشجوی کاردانی',
            'bachelor'           => 'کارشناسی',
            'master'             => 'کارشناسی ارشد',
            'phd'                => 'دکتری و بالاتر',
        ];
        $html .= '<div class="cbif-field col-md-6"><label for="education_status" class="form-label">وضعیت تحصیلی<span class="required text-danger">*</span></label><select id="education_status" name="education_status" class="crm-select2 form-select">';
        foreach ( $edu as $v => $t ) {
            $html .= '<option value="' . esc_attr( $v ) . '"' . $this->sel( $f['education_status'], $v ) . '>' . esc_html( $t ) . '</option>';
        }
        $html .= '</select></div>';
        $mil = [
            ''                  => '— انتخاب کنید —',
            'completed'         => 'پایان خدمت',
            'exempt'            => 'معافیت',
            'exempt_medical'    => 'معافیت پزشکی',
            'exempt_non_medical'=> 'معافیت غیر پزشکی',
            'exempt_leadership' => 'معافیت رهبری',
            'not_performed'     => 'انجام نداده',
            'studying'          => 'اشتغال به تحصیل',
            'seminarian'        => 'اشتغال به خدمت – طلبه',
        ];
        $html .= '<div class="cbif-field col-md-6"><label for="military_status" class="form-label">وضعیت خدمت<span class="required text-danger">*</span></label><select id="military_status" name="military_status" class="crm-select2 form-select">';
        foreach ( $mil as $v => $t ) {
            $html .= '<option value="' . esc_attr( $v ) . '"' . $this->sel( $f['military_status'], $v ) . '>' . esc_html( $t ) . '</option>';
        }
        $html .= '</select></div>';

        // residence
        $html .= '<div class="cbif-field col-md-6"><label class="form-label">استان/شهر اقامت<span class="required text-danger">*</span></label><select id="residence_province" name="residence_province" class="crm-select2 form-select"><option value="">— انتخاب کنید —</option>';
        foreach ( $provinces as $code => $name ) {
            $html .= '<option value="' . esc_attr( $code ) . '"' . $this->sel( $f['residence_province'], $code ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select>';
        $html .= '<select id="residence_city" name="residence_city" class="crm-select2 form-select mt-2"' . ( empty( $res ) ? ' disabled' : '' ) . '><option value="">' . ( empty( $res ) ? '— ابتدا استان را انتخاب کنید —' : '— انتخاب کنید —' ) . '</option>';
        foreach ( $res as $city ) {
            $html .= '<option value="' . esc_attr( $city ) . '"' . $this->sel( $f['residence_city'], $city ) . '>' . esc_html( $city ) . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<div class="cbif-field col-md-6"><label for="postal_code" class="form-label">کدپستی</label><input type="text" id="postal_code" name="postal_code" class="form-control" value="' . esc_attr( $f['postal_code'] ) . '"></div>';

        // address
        $html .= '<div class="cbif-field cbif-wide col-12"><label for="residence_address" class="form-label">آدرس محل سکونت<span class="required text-danger">*</span></label><textarea id="residence_address" name="residence_address" class="form-control">' . esc_textarea( $f['residence_address'] ) . '</textarea></div>';

        // contact
        $html .= '<div class="cbif-field col-md-6"><label for="billing_phone" class="form-label">موبایل<span class="required text-danger">*</span></label><input type="tel" id="billing_phone" name="billing_phone" class="form-control" value="' . esc_attr( $f['billing_phone'] ) . '" pattern="9[0-9]{9}"></div>';
        $html .= '<div class="cbif-field col-md-6"><label for="billing_email" class="form-label">ایمیل</label><input type="email" id="billing_email" name="billing_email" class="form-control" value="' . esc_attr( $f['billing_email'] ) . '"></div>';

        // bank and relations
        $html .= '<div class="cbif-field col-md-6"><label for="iban" class="form-label">شماره شبا (بدون IR)</label><input type="text" id="iban" name="iban" class="form-control" value="' . esc_attr( $f['iban'] ) . '"></div>';
        $html .= '<div class="cbif-field col-md-6"><label for="card_number" class="form-label">شماره کارت</label><input type="text" id="card_number" name="card_number" class="form-control" value="' . esc_attr( $f['card_number'] ) . '"></div>';
        $html .= '<div class="cbif-field col-md-6"><label for="coach_id" class="form-label">انتخاب مربی</label><select id="coach_id" name="coach_id" class="crm-select2 form-select">';
        foreach ( $coaches as $id => $name ) {
            $html .= '<option value="' . esc_attr( $id ) . '"' . $this->sel( $f['coach_id'], (string) $id ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<div class="cbif-field col-md-6"><label for="club_id" class="form-label">انتخاب باشگاه</label><select id="club_id" name="club_id" class="crm-select2 form-select">';
        foreach ( $clubs as $id => $name ) {
            $html .= '<option value="' . esc_attr( $id ) . '"' . $this->sel( $f['club_id'], (string) $id ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '</div>'; // row
        $html .= '<p class="cbif-submit"><button type="submit" name="save_basic_info" class="btn btn-primary">ذخیره اطلاعات</button></p>';
        $html .= '</form>';
        $html .= '</div>';
        return $html;
    }
}
