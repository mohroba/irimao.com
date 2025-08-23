<?php
namespace IMAOCustom\Forms;

use IMAOCustom\Logger;

class IdentityProfessionalForm extends BaseForm {
    protected string $nonce_action = 'imao_identity_professional';

    /**
     * Meta fields mapped to Persian labels.
     */
    private array $upload_fields = [
        'personal_photo'        => 'تصویر پرسنلی',
        'birth_certificate'     => 'تصویر شناسنامه',
        'national_id_card'      => 'تصویر کارت ملی',
        'education_certificate' => 'تصویر آخرین مدرک تحصیلی',
        'military_service_status' => 'تصویر کارت پایان خدمت/معافیت/اشتغال به تحصیل',
    ];

    /**
     * Per-field feedback messages.
     */
    private array $messages = [];

    public function fields(): array {
        $user_id = get_current_user_id();
        $out = [];
        foreach ( $this->upload_fields as $key => $_ ) {
            $out[ $key ] = get_user_meta( $user_id, $key, true );
        }
        $out['status']    = get_user_meta( $user_id, 'identity_verified_professional', true );
        $out['rejection'] = get_user_meta( $user_id, 'identity_rejection_reason_professional', true );
        return $out;
    }

    protected function submit(): void {
        $field = sanitize_text_field( $_POST['submit_field'] ?? '' );
        if ( ! $field || ! isset( $this->upload_fields[ $field ] ) ) {
            return;
        }
        $label   = $this->upload_fields[ $field ];
        $user_id = get_current_user_id();
        if ( empty( $_FILES[ $field ]['name'] ) ) {
            $this->messages[ $field ] = [ 'error' => 'فایل الزامی است.' ];
            return;
        }

        $file = $_FILES[ $field ];
        $mime = $file['type'] ?? '';
        $size = (int) ( $file['size'] ?? 0 );
        if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'application/pdf' ], true ) ) {
            $this->messages[ $field ] = [ 'error' => 'فرمت فایل نامعتبر است.' ];
            return;
        }
        if ( $size > 1024 * 1024 ) {
            $this->messages[ $field ] = [ 'error' => 'حجم فایل باید حداکثر ۱ مگابایت باشد.' ];
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        add_filter( 'upload_dir', function ( $dirs ) use ( $user_id ) {
            $sub            = '/identity_verification/user_' . $user_id;
            $dirs['subdir'] = $sub;
            $dirs['path']   = $dirs['basedir'] . $sub;
            $dirs['url']    = $dirs['baseurl'] . $sub;
            if ( ! file_exists( $dirs['path'] ) ) {
                wp_mkdir_p( $dirs['path'] );
            }
            return $dirs;
        } );

        $up = wp_handle_upload( $file, [ 'test_form' => false ] );
        remove_all_filters( 'upload_dir' );

        if ( isset( $up['url'] ) && empty( $up['error'] ) ) {
            update_user_meta( $user_id, $field, esc_url_raw( $up['url'] ) );
            update_user_meta( $user_id, 'identity_verified_professional', 'pending' );
            delete_user_meta( $user_id, 'identity_rejection_reason_professional' );
            $this->messages[ $field ] = [ 'success' => 'فایل با موفقیت بارگذاری شد و در انتظار بررسی است.' ];
            Logger::info( 'Uploaded professional identity file', [ 'user' => $user_id, 'field' => $field ] );
        } else {
            $this->messages[ $field ] = [ 'error' => 'بارگذاری فایل ناموفق بود.' ];
            Logger::error( 'Failed uploading professional identity file', [
                'user'  => $user_id,
                'field' => $field,
                'error' => $up['error'] ?? 'unknown',
            ] );
        }
    }

    private function has_basic_info( int $user_id ): bool {
        $required = [ 'national_id','first_name_fa','last_name_fa','gender','father_name','birth_date','birth_province','birth_city','marital_status','education_status','residence_province','residence_city','residence_address','billing_phone','billing_email' ];
        foreach ( $required as $k ) {
            if ( get_user_meta( $user_id, $k, true ) === '' ) {
                return false;
            }
        }
        return true;
    }

    public function render(): string {
        if ( ! is_user_logged_in() ) {
            return '<p>لطفاً وارد شوید تا بتوانید اطلاعات را ثبت کنید.</p>';
        }
        $user_id = get_current_user_id();
        if ( ! $this->has_basic_info( $user_id ) ) {
            return '<div class="notice-warning">ابتدا اطلاعات پایه را تکمیل کنید.</div>';
        }

        $fields = $this->fields();
        $status = $fields['status'];
        $reject = $fields['rejection'];

        ob_start();
        echo '<div class="crm-identity-verification-form">';
        echo '<form method="post" enctype="multipart/form-data">';
        echo wp_nonce_field( $this->nonce_action, $this->nonce_name, true, false );
        echo $this->error_list();

        if ( $status === 'approved' ) {
            echo '<div class="notice-success">مدارک شما تایید شده است و نمی‌توانید تغییرات ایجاد کنید.</div>';
        } elseif ( $status === 'pending' ) {
            echo '<div class="notice-warning">مدارک شما در انتظار بررسی است.</div>';
        } elseif ( $status === 'disapproved' && $reject ) {
            echo '<div class="notice-error">دلیل رد هویت: ' . esc_html( $reject ) . '</div>';
        }

        foreach ( $this->upload_fields as $key => $label ) {
            $current = $fields[ $key ];
            echo '<div class="upload-item">';
            echo '<div class="upload-input">';
            echo '<label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
            echo '<div class="custom-file-wrapper" data-nofile="فایلی انتخاب نشده">';
            echo '<button type="button" class="custom-file-btn">انتخاب فایل</button>';
            echo '<input type="file" name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '" accept=".jpg,.jpeg,.png,.pdf">';
            echo '</div>';
            echo '<span class="file-name">فایلی انتخاب نشده</span>';
            if ( $key === 'personal_photo' ) {
                echo '<div class="upload-notice"><p>تصویر حتما باید با پس زمینه سفید گرفته شده باشد.</p><p>عکس پرسنلی حتما بصورت تمام رخ باشد.</p><p>رعایت شئونات و عرف اسلام و جامعه در عکس‌ها الزامی می‌باشد.</p><p>لطفا از گرفتن عکس از روی تصویر فیزیکی خودداری کنید.</p><p>حداکثر حجم فایل‌ها، یک مگابایت می‌باشد.</p><p>فایل‌هایی با پسوندهای jpg، jpeg و pdf مجاز می‌باشد.</p></div>';
            } else {
                echo '<div class="upload-notice"><p>حداکثر حجم فایل 1 مگابایت است.</p><p>فرمت‌های مجاز: jpg، jpeg، png، pdf.</p></div>';
            }
            if ( isset( $this->messages[ $key ] ) ) {
                $type = isset( $this->messages[ $key ]['error'] ) ? 'upload-error' : 'upload-success';
                $text = reset( $this->messages[ $key ] );
                echo '<p class="' . $type . '">' . esc_html( $text ) . '</p>';
            }
            echo '</div>';
            echo '<div class="upload-thumbnail">';
            if ( $current ) {
                echo '<a href="' . esc_url( $current ) . '" target="_blank"><img src="' . esc_url( $current ) . '" alt=""></a>';
            }
            echo '<div style="margin-top:10px;">';
            echo '<button type="submit" class="button-submit" name="submit_field" value="' . esc_attr( $key ) . '">ثبت اطلاعات</button>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }

        echo '<p class="form-note">لطفاً پس از بارگذاری هر فایل، دکمه ثبت اطلاعات را بزنید تا فایل شما ثبت شود.</p>';
        echo '<p class="form-disclaimer">مسئولیت هرگونه مغایرت اطلاعات وارد شده با مستندات آپلود شده بعهده کاربر است.</p>';
        echo '</form></div>';
        return (string) ob_get_clean();
    }
}
