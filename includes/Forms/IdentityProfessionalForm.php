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
        $user_id = get_current_user_id();
        foreach ( $this->upload_fields as $key => $label ) {
            if ( empty( $_FILES[ $key ]['name'] ) ) {
                continue;
            }

            $file = $_FILES[ $key ];
            $mime = $file['type'] ?? '';
            if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'application/pdf' ], true ) ) {
                $this->errors[] = sprintf( '%s باید تصویر یا PDF باشد.', $label );
                continue;
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            add_filter( 'upload_dir', function( $dirs ) use ( $user_id ) {
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
                update_user_meta( $user_id, $key, esc_url_raw( $up['url'] ) );
                update_user_meta( $user_id, 'identity_verified_professional', 'pending' );
                delete_user_meta( $user_id, 'identity_rejection_reason_professional' );
                Logger::info( 'Uploaded professional identity file', [ 'user' => $user_id, 'field' => $key ] );
            } else {
                $this->errors[] = sprintf( 'بارگذاری %s ناموفق بود.', $label );
                Logger::error( 'Failed uploading professional identity file', [
                    'user'  => $user_id,
                    'field' => $key,
                    'error' => $up['error'] ?? 'unknown',
                ] );
            }
        }
    }

    public function render(): string {
        if ( ! is_user_logged_in() ) {
            return '<p>لطفاً وارد شوید تا بتوانید اطلاعات را ثبت کنید.</p>';
        }

        $fields   = $this->fields();
        $status   = $fields['status'];
        $reject   = $fields['rejection'];
        $html     = '<form method="post" enctype="multipart/form-data">';
        $html    .= wp_nonce_field( $this->nonce_action, $this->nonce_name, true, false );
        $html    .= $this->error_list();

        if ( $status === 'approved' ) {
            $html .= '<p>مدارک شما تایید شده است.</p>';
        } elseif ( $status === 'pending' ) {
            $html .= '<p>مدارک شما در انتظار بررسی است.</p>';
        }
        if ( $reject ) {
            $html .= '<p>دلیل رد: ' . esc_html( $reject ) . '</p>';
        }

        foreach ( $this->upload_fields as $key => $label ) {
            $current = $fields[ $key ];
            $html   .= '<p><label>' . esc_html( $label ) . ': ';
            $html   .= '<input type="file" name="' . esc_attr( $key ) . '">';
            if ( $current ) {
                $html .= ' <a href="' . esc_url( $current ) . '" target="_blank">فایل قبلی</a>';
            }
            $html   .= '</label></p>';
        }

        $html .= '<p><button type="submit">ارسال</button></p>';
        $html .= '</form>';
        return $html;
    }
}
