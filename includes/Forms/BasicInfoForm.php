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
        $uid    = get_current_user_id();
        $user   = get_userdata( $uid );
        $fields = UserMeta::get_many( $uid, $this->meta_keys() );
        $fields['billing_email'] = $user ? $user->user_email : '';
        $clubs = get_user_meta( $uid, 'clubs', true );
        $fields['clubs'] = is_array( $clubs ) ? $clubs : [];
        if ( ! empty( $this->posted ) ) {
            $fields = array_merge( $fields, $this->posted );
        }
        return $fields;
    }

    /**
     * Handle form submission with validation.
     */
    protected function submit(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }
        $uid    = get_current_user_id();
        $status = get_user_meta( $uid, 'identity_verified_professional', true );
        $locked = $status === 'approved';

        $data     = [];
        $all_keys = array_merge( $this->meta_keys(), [ 'billing_email' ] );
        foreach ( $all_keys as $key ) {
            if ( $key === 'residence_address' ) {
                $data[ $key ] = sanitize_textarea_field( $_POST[ $key ] ?? '' );
            } elseif ( $key === 'billing_email' ) {
                $data[ $key ] = sanitize_email( $_POST[ $key ] ?? '' );
            } elseif ( $key === 'birth_date' ) {
                continue;
            } else {
                $data[ $key ] = sanitize_text_field( $_POST[ $key ] ?? '' );
            }
        }
        $data['birth_date'] = $this->read_date( 'birth_date' );
        $selected_gender = $locked ? $this->normalize_gender( (string) get_user_meta( $uid, 'gender', true ) ) : $this->normalize_gender( (string) ( $data['gender'] ?? '' ) );
        if ( ! $this->coach_matches_gender( (int) ( $data['coach_id'] ?? 0 ), $selected_gender ) ) {
            $this->errors[] = 'مربی انتخاب‌شده با جنسیت شما مطابقت ندارد.';
            $this->posted = $data;
            return;
        }

        if ( $locked ) {
            foreach ( [ 'coach_id', 'club_id' ] as $k ) {
                update_user_meta( $uid, $k, $data[ $k ] ?? '' );
            }
            $this->saved  = true;
            $this->errors[] = 'یادآوری : ویرایش سایر اطلاعات پس از تأیید امکان‌پذیر نیست. (به غیر از نام باشگاه و مربی)';
            return;
        }

        $this->posted = $data;

        $required = [
            'billing_phone','national_id','first_name_fa','last_name_fa','gender','father_name',
            'birth_date','birth_province','birth_city','marital_status','education_status',
            'residence_province','residence_city','residence_address',
        ];
        $labels = [
            'billing_phone'      => 'موبایل',
            'national_id'        => 'کد ملی',
            'first_name_fa'      => 'نام',
            'last_name_fa'       => 'نام خانوادگی',
            'gender'             => 'جنسیت',
            'father_name'        => 'نام پدر',
            'birth_date'         => 'تاریخ تولد',
            'birth_province'     => 'استان محل تولد',
            'birth_city'         => 'شهرستان محل تولد',
            'marital_status'     => 'وضعیت تأهل',
            'education_status'   => 'وضعیت تحصیلی',
            'military_status'    => 'وضعیت خدمت',
            'residence_province' => 'استان محل سکونت',
            'residence_city'     => 'شهرستان محل سکونت',
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
            $result = wp_update_user( [ 'ID' => $uid, 'user_email' => $data['billing_email'] ] );
            if ( is_wp_error( $result ) ) {
                $this->errors[] = $result->get_error_message();
                return;
            }
        }
        update_user_meta( $uid, 'billing_email', $data['billing_email'] );
        unset( $data['billing_email'] );
        foreach ( $this->meta_keys() as $k ) {
            update_user_meta( $uid, $k, $data[ $k ] ?? '' );
        }
        wp_update_user( [
            'ID'           => $uid,
            'display_name' => trim( $data['first_name_fa'] . ' ' . $data['last_name_fa'] ),
            'nickname'     => trim( $data['first_name_fa'] . ' ' . $data['last_name_fa'] ),
        ] );
        clean_user_cache( $uid );
        $this->saved  = true;
        $this->posted = [];
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
    private function coach_options( string $gender = '' ): array {
        $gender = $this->normalize_gender( $gender );
        $records = $this->coach_records();
        $out = [ '' => '— انتخاب مربی —' ];
        foreach ( $records as $id => $record ) {
            if ( $gender !== '' && $record['gender'] !== $gender ) {
                continue;
            }
            $out[ $id ] = $record['name'];
        }
        return $out;
    }

    /**
     * Approved coaches indexed by user ID.
     *
     * @return array<int,array{name:string,gender:string}>
     */
    private function coach_records(): array {
        $current = get_current_user_id();
        $users   = get_users( [
            'role'       => 'coach',
            'meta_key'   => 'identity_verified_professional',
            'meta_value' => 'approved',
            'fields'     => [ 'ID', 'display_name' ],
        ] );
        $out = [];
        foreach ( $users as $u ) {
            if ( (int) $u->ID === $current ) {
                continue;
            }
            $out[ (int) $u->ID ] = [
                'name'   => (string) $u->display_name,
                'gender' => $this->normalize_gender( (string) get_user_meta( (int) $u->ID, 'gender', true ) ),
            ];
        }
        return $out;
    }

    private function coach_matches_gender( int $coach_id, string $gender ): bool {
        if ( $coach_id <= 0 || $gender === '' ) {
            return true;
        }
        $coach_gender = $this->normalize_gender( (string) get_user_meta( $coach_id, 'gender', true ) );
        return $coach_gender !== '' && $coach_gender === $gender;
    }

    private function normalize_gender( string $gender ): string {
        $gender = strtolower( trim( $gender ) );
        if ( in_array( $gender, [ 'male', 'men' ], true ) ) {
            return 'male';
        }
        if ( in_array( $gender, [ 'female', 'women' ], true ) ) {
            return 'female';
        }
        return '';
    }

    /**
     * Fetch all approved clubs with their owning coach and address.
     *
     * @return array<int, array<string, mixed>>
     */
    private function club_data(): array {
        $posts = get_posts( [
            'post_type'   => 'club_application',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ] );
        $out = [];
        foreach ( $posts as $p ) {
            $out[] = [
                'id'      => $p->ID,
                'name'    => $p->post_title,
                'address' => get_post_meta( $p->ID, 'club_address', true ),
                'coach'   => (int) $p->post_author,
            ];
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
        $provinces = CityMap::get_provinces();
        $birth     = CityMap::get_cities( (string) $f['birth_province'] );
        $res       = CityMap::get_cities( (string) $f['residence_province'] );
        $coaches   = $this->coach_options( (string) $f['gender'] );
        $coach_records = $this->coach_records();
        $club_data = $this->club_data();
        $clubs     = array_filter(
            $club_data,
            static fn( $c ) => (string) $c['coach'] === (string) $f['coach_id']
        );
        $clubs_map = [];
        foreach ( $club_data as $c ) {
            $clubs_map[ $c['coach'] ][] = [
                'id'      => $c['id'],
                'name'    => $c['name'],
                'address' => $c['address'],
            ];
        }
        if ( function_exists( 'wp_localize_script' ) ) {
            wp_localize_script( 'imao-edit-basic-info', 'CBIF_CLUBS', $clubs_map );
            wp_localize_script( 'imao-edit-basic-info', 'CBIF_COACHES', $coach_records );
        }

        $html = '';
        if ( $status === 'approved' ) {
            $html .= '<div style="background:#d1ecf1;border:1px solid #bee5eb;color:#0c5460;padding:15px;border-radius:4px;margin-bottom:20px;">'
                . __( 'اطلاعات پایه شما تأیید شده است و امکان ویرایش سایر فیلدها وجود ندارد؛ تنها انتخاب مربی و باشگاه قابل تغییر است.', 'imao-custom-plugin' )
                . '</div>';
        }
        $html .= '<div style="background:#ffe8e8;border:1px solid #f5c6cb;color:#721c24;padding:15px;border-radius:4px;margin-bottom:20px;">'
            . __( 'شما فقط یکبار اجازه ورود و بروزرسانی اطلاعات پایه را دارید، پس در تکمیل اطلاعات پایه، دقت کافی را داشته باشید. پس از ثبت اطلاعات، تغییر یا بروزرسانی اطلاعات فقط با هماهنگی کمیته آموزش سبک امکان‌پذیر خواهد بود.', 'imao-custom-plugin' )
            . '</div>';
        $html .= '<div style="background:#ffe8e8;border:1px solid #f5c6cb;color:#721c24;padding:15px;border-radius:4px;margin-bottom:20px;">'
            . __( 'مسئولیت هرگونه مغایرت اطلاعات وارد شده در این صفحه با فایل‌ها و مستندات آپلود شده در سیستم، کاملا بعهده کاربر بوده و در صورت مشاهده مغایرت، این امر تخلف شمرده شده و احتمال مسدود شدن حساب کاربری وجود خواهد داشت.', 'imao-custom-plugin' )
            . '</div>';
        $html .= '<div class="sd-container">';
        $html .= '<div class="sd-header" style="margin-bottom: 15px">اطلاعات پایه</div>';
        $html .= '<form method="post" id="id-form" class="needs-swal" data-status="' . esc_attr( $status ) . '">';
        $html .= wp_nonce_field( $this->nonce_action, $this->nonce_name, true, false );
        $html .= $this->success_message();
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
        $html .= '<div class="cbif-field"><label>تاریخ تولد<span class="required">*</span></label>'
            . $this->date_select( 'birth_date', $f['birth_date'], true )
            . '</div>';
        $html .= '<div class="cbif-field"><label for="birth_province">استان محل تولد<span class="required">*</span></label><select id="birth_province" name="birth_province" class="crm-select2"><option value="">— انتخاب کنید —</option>';
        foreach ( $provinces as $code => $name ) {
            $html .= '<option value="' . esc_attr( $code ) . '"' . $this->sel( $f['birth_province'], $code ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<div class="cbif-field"><label for="birth_city">شهرستان محل تولد<span class="required">*</span></label><select id="birth_city" name="birth_city" class="crm-select2"' . ( empty( $birth ) ? ' disabled' : '' ) . '><option value="">' . ( empty( $birth ) ? '— ابتدا استان را انتخاب کنید —' : '— انتخاب کنید —' ) . '</option>';
        foreach ( $birth as $city ) {
            $html .= '<option value="' . esc_attr( $city ) . '"' . $this->sel( $f['birth_city'], $city ) . '>' . esc_html( $city ) . '</option>';
        }
        $html .= '</select></div>';

        // marital, education, military (optional)
        $html .= '<div class="cbif-field"><label for="marital_status">وضعیت تأهل<span class="required">*</span></label><select id="marital_status" name="marital_status" class="crm-select2"><option value="">— انتخاب کنید —</option><option value="single"' . $this->sel( $f['marital_status'], 'single' ) . '>مجرد</option><option value="married"' . $this->sel( $f['marital_status'], 'married' ) . '>متأهل</option></select></div>';
        $edu = [
            '' => '— انتخاب کنید —',
            'student_primary'    => 'محصل - ابتدایی',
            'student_highschool' => 'محصل - دبیرستان',
            'student_middle_school' => 'محصل - راهنمایی',
            'diploma'            => 'دیپلم',
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
        $html .= '<div class="cbif-field"><label for="military_status">وضعیت خدمت</label><select id="military_status" name="military_status" class="crm-select2">';
        foreach ( $mil as $v => $t ) {
            $html .= '<option value="' . esc_attr( $v ) . '"' . $this->sel( $f['military_status'], $v ) . '>' . esc_html( $t ) . '</option>';
        }
        $html .= '</select></div>';

        // residence
        $html .= '<div class="cbif-field"><label for="residence_province">استان محل سکونت<span class="required">*</span></label><select id="residence_province" name="residence_province" class="crm-select2"><option value="">— انتخاب کنید —</option>';
        foreach ( $provinces as $code => $name ) {
            $html .= '<option value="' . esc_attr( $code ) . '"' . $this->sel( $f['residence_province'], $code ) . '>' . esc_html( $name ) . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<div class="cbif-field"><label for="residence_city">شهرستان محل سکونت<span class="required">*</span></label><select id="residence_city" name="residence_city" class="crm-select2"' . ( empty( $res ) ? ' disabled' : '' ) . '><option value="">' . ( empty( $res ) ? '— ابتدا استان را انتخاب کنید —' : '— انتخاب کنید —' ) . '</option>';
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
        $html .= '<option value="">— انتخاب باشگاه —</option>';
        foreach ( $clubs as $c ) {
            $html .= '<option value="' . esc_attr( $c['id'] ) . '" data-address="' . esc_attr( $c['address'] ) . '"' . $this->sel( $f['club_id'], (string) $c['id'] ) . '>' . esc_html( $c['name'] ) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '</div>'; // grid
        $html .= '<p class="cbif-submit"><button type="submit" name="save_basic_info">ذخیره اطلاعات</button></p>';
        $html .= '</form>';
        $html .= '</div>';
        return $html;
    }
}
