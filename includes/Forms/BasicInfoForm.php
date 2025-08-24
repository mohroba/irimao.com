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
        $uid     = get_current_user_id();
        $status  = get_user_meta( $uid, 'identity_verified_professional', true );
        $locked  = ! in_array( $status, [ 'pending', 'rejected' ], true );

        $data     = [];
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

        if ( $locked ) {
            foreach ( [ 'coach_id', 'club_id' ] as $k ) {
                update_user_meta( $uid, $k, $data[ $k ] ?? '' );
            }
            return;
        }

        $gender   = $data['gender'] ?? '';
        $required = [
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
        $uid       = get_current_user_id();
        $status    = get_user_meta( $uid, 'identity_verified_professional', true );
        $provinces = class_exists( '\\WC_Countries' ) ? ( new \WC_Countries() )->get_states( 'IR' ) : [];
        $birth     = CityMap::get_cities( (string) $f['birth_province'] );
        $res       = CityMap::get_cities( (string) $f['residence_province'] );
        $coaches   = $this->coach_options();
        $clubs     = $this->club_options();

        $html  = '<div class="sd-container">';
        $html .= '<div class="sd-header" style="margin-bottom: 15px">اطلاعات پایه</div>';
        $html .= '<div style="background:#ffe8e8;border:1px solid #f5c6cb;color:#721c24;padding:15px;border-radius:4px;margin-bottom:20px;">'
            . __( 'شما فقط یکبار اجازه ورود و بروزرسانی اطلاعات پایه را دارید، پس در تکمیل اطلاعات پایه، دقت کافی را داشته باشید. پس از ثبت اطلاعات، تغییر یا بروزرسانی اطلاعات فقط با هماهنگی کمیته آموزش سبک امکان‌پذیر خواهد بود.', 'imao-custom-plugin' )
            . '</div>';
        $html .= '<div style="background:#ffe8e8;border:1px solid #f5c6cb;color:#721c24;padding:15px;border-radius:4px;margin-bottom:20px;">'
            . __( 'مسئولیت هرگونه مغایرت اطلاعات وارد شده در این صفحه با فایل‌ها و مستندات آپلود شده در سیستم، کاملا بعهده کاربر بوده و در صورت مشاهده مغایرت، این امر تخلف شمرده شده و احتمال مسدود شدن حساب کاربری وجود خواهد داشت.', 'imao-custom-plugin' )
            . '</div>';
        $html .= '<form method="post" id="id-form" class="needs-swal" data-status="' . esc_attr( $status ) . '">';
        $html .= wp_nonce_field( $this->nonce_action, $this->nonce_name, true, false );
        $html .= $this->error_list();
        $html .= '<div class="cbif-grid">';

        // national id & gender
        $html .= '<div class="cbif-field"><label for="national_id_display">کد ملی<span class="required">*</span></label>';
        $html .= '<input type="text" id="national_id_display" value="' . esc_attr( $f['national_id'] ) . '" disabled>';
        $html .= '<input type="hidden" id="national_id" name="national_id" value="' . esc_attr( $f['national_id'] ) . '"></div>';
        $html .= '<div class="cbif-field"><label for="gender">جنسیت<span class="required">*</span></label><select id="gender" name="gender" class="crm-select2"><option value="">— انتخاب کنید —</option><option value="male"' . $this->sel( $f['gender'], 'male' ) . '>مرد</option><option value="female"' . $this->sel( $f['gender'], 'female' ) . '>زن</option></select></div>';

        // names
        $html .= '<div class="cbif-field"><label for="first_name_fa">نام (فارسی)<span class="required">*</span></label><input type="text" id="first_name_fa" name="first_name_fa" value="' . esc_attr( $f['first_name_fa'] ) . '"></div>';
        $html .= '<div class="cbif-field"><label for="last_name_fa">نام خانوادگی (فارسی)<span class="required">*</span></label><input type="text" id="last_name_fa" name="last_name_fa" value="' . esc_attr( $f['last_name_fa'] ) . '"></div>';
        $html .= '<div class="cbif-field"><label for="first_name_en">نام (En)</label><input type="text" id="first_name_en" name="first_name_en" value="' . esc_attr( $f['first_name_en'] ) . '"></div>';
        $html .= '<div class="cbif-field"><label for="last_name_en">نام‌خانوادگی (En)</label><input type="text" id="last_name_en" name="last_name_en" value="' . esc_attr( $f['last_name_en'] ) . '"></div>';

        // father, birth
        $html .= '<div class="cbif-field"><label for="father_name">نام پدر<span class="required">*</span></label><input type="text" id="father_name" name="father_name" value="' . esc_attr( $f['father_name'] ) . '"></div>';
        $html .= '<div class="cbif-field"><label for="birth_date">تاریخ تولد<span class="required">*</span></label><input type="text" id="birth_date" name="birth_date" class="persian-date" data-jdp data-jdp-only-date value="' . esc_attr( $f['birth_date'] ) . '"></div>';
        $html .= '<div class="cbif-field"><label>استان/شهر تولد<span class="required">*</span></label><select id="birth_province" name="birth_province" class="crm-select2"><option value="">— انتخاب کنید —</option>';
        foreach ( $provinces as $code => $name ) {
            $html .= '<option value="' . esc_attr( $code ) . '"' . $this->sel( $f['birth_province'], $code ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select>';
        $html .= '<select id="birth_city" name="birth_city" class="crm-select2" style="margin-top:8px;"' . ( empty( $birth ) ? ' disabled' : '' ) . '><option value="">' . ( empty( $birth ) ? '— ابتدا استان را انتخاب کنید —' : '— انتخاب کنید —' ) . '</option>';
        foreach ( $birth as $city ) {
            $html .= '<option value="' . esc_attr( $city ) . '"' . $this->sel( $f['birth_city'], $city ) . '>' . esc_html( $city ) . '</option>';
        }
        $html .= '</select></div>';

        // marital, education, military
        $html .= '<div class="cbif-field"><label for="marital_status">وضعیت تأهل<span class="required">*</span></label><select id="marital_status" name="marital_status" class="crm-select2"><option value="">— انتخاب کنید —</option><option value="single"' . $this->sel( $f['marital_status'], 'single' ) . '>مجرد</option><option value="married"' . $this->sel( $f['marital_status'], 'married' ) . '>متأهل</option></select></div>';
        $edu = [
            '' => '— انتخاب کنید —',
            'student_primary'    => 'محصل - ابتدایی',
            'student_highschool' => 'محصل - دبیرستان',
            'student_college'    => 'دانشجوی کاردانی',
            'bachelor'           => 'کارشناسی',
            'master'             => 'کارشناسی ارشد',
            'phd'                => 'دکتری و بالاتر',
        ];
        $html .= '<div class="cbif-field"><label for="education_status">وضعیت تحصیلی<span class="required">*</span></label><select id="education_status" name="education_status" class="crm-select2">';
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
        $html .= '<div class="cbif-field"><label for="military_status">وضعیت خدمت<span class="required">*</span></label><select id="military_status" name="military_status" class="crm-select2">';
        foreach ( $mil as $v => $t ) {
            $html .= '<option value="' . esc_attr( $v ) . '"' . $this->sel( $f['military_status'], $v ) . '>' . esc_html( $t ) . '</option>';
        }
        $html .= '</select></div>';

        // residence
        $html .= '<div class="cbif-field"><label>استان/شهر اقامت<span class="required">*</span></label><select id="residence_province" name="residence_province" class="crm-select2"><option value="">— انتخاب کنید —</option>';
        foreach ( $provinces as $code => $name ) {
            $html .= '<option value="' . esc_attr( $code ) . '"' . $this->sel( $f['residence_province'], $code ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select>';
        $html .= '<select id="residence_city" name="residence_city" class="crm-select2" style="margin-top:8px;"' . ( empty( $res ) ? ' disabled' : '' ) . '><option value="">' . ( empty( $res ) ? '— ابتدا استان را انتخاب کنید —' : '— انتخاب کنید —' ) . '</option>';
        foreach ( $res as $city ) {
            $html .= '<option value="' . esc_attr( $city ) . '"' . $this->sel( $f['residence_city'], $city ) . '>' . esc_html( $city ) . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<div class="cbif-field"><label for="postal_code">کدپستی</label><input type="text" id="postal_code" name="postal_code" value="' . esc_attr( $f['postal_code'] ) . '"></div>';

        // address
        $html .= '<div class="cbif-field cbif-wide"><label for="residence_address">آدرس محل سکونت<span class="required">*</span></label><textarea id="residence_address" name="residence_address">' . esc_textarea( $f['residence_address'] ) . '</textarea></div>';

        // contact
        $html .= '<div class="cbif-field"><label for="billing_phone">موبایل<span class="required">*</span></label><input type="tel" id="billing_phone" name="billing_phone" value="' . esc_attr( $f['billing_phone'] ) . '" pattern="9[0-9]{9}"></div>';
        $html .= '<div class="cbif-field"><label for="billing_email">ایمیل</label><input type="email" id="billing_email" name="billing_email" value="' . esc_attr( $f['billing_email'] ) . '"></div>';

        // bank and relations
        $html .= '<div class="cbif-field"><label for="iban">شماره شبا (بدون IR)</label><input type="text" id="iban" name="iban" value="' . esc_attr( $f['iban'] ) . '"></div>';
        $html .= '<div class="cbif-field"><label for="card_number">شماره کارت</label><input type="text" id="card_number" name="card_number" value="' . esc_attr( $f['card_number'] ) . '"></div>';
        $html .= '<div class="cbif-field"><label for="coach_id">انتخاب مربی</label><select id="coach_id" name="coach_id" class="crm-select2">';
        foreach ( $coaches as $id => $name ) {
            $html .= '<option value="' . esc_attr( $id ) . '"' . $this->sel( $f['coach_id'], (string) $id ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<div class="cbif-field"><label for="club_id">انتخاب باشگاه</label><select id="club_id" name="club_id" class="crm-select2">';
        foreach ( $clubs as $id => $name ) {
            $html .= '<option value="' . esc_attr( $id ) . '"' . $this->sel( $f['club_id'], (string) $id ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '</div>'; // grid
        $html .= '<p class="cbif-submit"><button type="submit" name="save_basic_info">ذخیره اطلاعات</button></p>';
        $html .= '</form>';
        $html .= '</div>';
        return $html;
    }
}
