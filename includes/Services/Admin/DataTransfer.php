<?php

namespace IMAOCustom\Services\Admin;

use RuntimeException;

class DataTransfer {
    private const FORMAT = 'imao-backup-v1';
    private const POST_TYPES = [ 'self_declaration', 'club_application', 'style_committe_request', 'course', 'competition' ];
    private const TABLES = [ 'crm_points', 'crm_settings', 'crm_province_reps', 'crm_city_reps', 'crm_city_rep_requests' ];

    public function register(): void {
        add_action( 'imao_plugin_settings_after_sections', [ $this, 'render' ] );
        add_action( 'admin_post_imao_export_data', [ $this, 'download' ] );
        add_action( 'admin_post_imao_import_data', [ $this, 'upload' ] );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $notice = isset( $_GET['imao_transfer'] ) ? sanitize_key( wp_unslash( $_GET['imao_transfer'] ) ) : '';
        if ( $notice === 'success' ) {
            echo '<div class="notice notice-success"><p>بازیابی اطلاعات با موفقیت انجام شد.</p></div>';
        } elseif ( $notice === 'error' ) {
            $message = isset( $_GET['imao_message'] ) ? sanitize_text_field( wp_unslash( $_GET['imao_message'] ) ) : 'فایل پشتیبان معتبر نیست.';
            echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
        }
        ?>
        <hr>
        <h2>پشتیبان‌گیری و بازیابی کامل</h2>
        <p>فایل JSON شامل کاربران، فراداده کاربران، نقش‌ها، محتوای ساخته‌شده توسط افزونه، دسته‌بندی‌ها، تنظیمات و جداول اختصاصی است. گذرواژه‌ها، نشست‌ها و کلیدهای امنیتی عمداً صادر نمی‌شوند.</p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="imao_export_data">
            <?php wp_nonce_field( 'imao_export_data' ); ?>
            <?php submit_button( 'دریافت فایل پشتیبان کامل', 'secondary', 'submit', false ); ?>
        </form>
        <h3>بازیابی</h3>
        <p>بازیابی به‌صورت ادغام انجام می‌شود؛ کاربران با ایمیل/نام کاربری و محتوا با شناسه منبع تطبیق داده می‌شوند و گذرواژه کاربران موجود تغییر نمی‌کند.</p>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="imao_import_data">
            <?php wp_nonce_field( 'imao_import_data' ); ?>
            <input type="file" name="imao_backup" accept="application/json,.json" required>
            <label style="display:block;margin:12px 0"><input type="checkbox" name="confirm_import" value="1" required> از صحت فایل و تهیه نسخه پشتیبان فعلی مطمئن هستم.</label>
            <?php submit_button( 'بازیابی و ادغام اطلاعات', 'primary', 'submit', false ); ?>
        </form>
        <?php
    }

    public function download(): void {
        $this->authorize( 'imao_export_data' );
        $json = wp_json_encode( $this->export_data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) ) {
            wp_die( 'ساخت فایل پشتیبان ناموفق بود.' );
        }
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="imao-backup-' . gmdate( 'Y-m-d-His' ) . '.json"' );
        header( 'Content-Length: ' . strlen( $json ) );
        echo $json;
        exit;
    }

    public function upload(): void {
        $this->authorize( 'imao_import_data' );
        try {
            if ( empty( $_POST['confirm_import'] ) ) {
                throw new RuntimeException( 'تأیید بازیابی الزامی است.' );
            }
            $file = $_FILES['imao_backup'] ?? [];
            if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
                throw new RuntimeException( 'بارگذاری فایل ناموفق بود.' );
            }
            if ( (int) ( $file['size'] ?? 0 ) > 100 * MB_IN_BYTES ) {
                throw new RuntimeException( 'حجم فایل بیشتر از ۱۰۰ مگابایت است.' );
            }
            $payload = json_decode( (string) file_get_contents( $file['tmp_name'] ), true );
            $this->validate_payload( $payload );
            $this->import_data( $payload );
            $this->redirect( 'success' );
        } catch ( \Throwable $e ) {
            $this->redirect( 'error', $e->getMessage() );
        }
    }

    public function export_data(): array {
        global $wpdb;
        $users = $wpdb->get_results( "SELECT ID,user_login,user_nicename,user_email,user_url,user_registered,display_name FROM {$wpdb->users} ORDER BY ID", ARRAY_A );
        $user_meta = [];
        foreach ( $users as $user ) {
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key,meta_value FROM {$wpdb->usermeta} WHERE user_id=%d", $user['ID'] ), ARRAY_A );
            $user_meta[ $user['ID'] ] = array_values( array_filter( $rows, fn( $row ) => ! $this->is_sensitive_meta( (string) $row['meta_key'] ) ) );
        }

        $posts = get_posts( [ 'post_type' => self::POST_TYPES, 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC' ] );
        $post_data = [];
        foreach ( $posts as $post ) {
            $terms = [];
            foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
                $assigned = wp_get_object_terms( $post->ID, $taxonomy );
                if ( ! is_wp_error( $assigned ) ) {
                    $terms[ $taxonomy ] = array_map( function ( $term ) {
                        $parent = $term->parent ? get_term( $term->parent ) : null;
                        return [ 'slug' => $term->slug, 'name' => $term->name, 'parent' => $parent && ! is_wp_error( $parent ) ? $parent->slug : '' ];
                    }, $assigned );
                }
            }
            $post_data[] = [
                'source_id' => (int) $post->ID, 'post_author' => (int) $post->post_author, 'post_date' => $post->post_date,
                'post_content' => $post->post_content, 'post_title' => $post->post_title, 'post_excerpt' => $post->post_excerpt,
                'post_status' => $post->post_status, 'comment_status' => $post->comment_status, 'ping_status' => $post->ping_status,
                'post_name' => $post->post_name, 'post_parent' => (int) $post->post_parent, 'menu_order' => (int) $post->menu_order,
                'post_type' => $post->post_type, 'meta' => get_post_meta( $post->ID ), 'terms' => $terms,
            ];
        }

        $tables = [];
        foreach ( self::TABLES as $suffix ) {
            $table = $wpdb->prefix . $suffix;
            $tables[ $suffix ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table
                ? $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A ) : [];
        }
        $options_like = $wpdb->esc_like( 'imao_' ) . '%';
        $options = $wpdb->get_results( $wpdb->prepare( "SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name", $options_like ), ARRAY_A );
        return [ 'format' => self::FORMAT, 'created_at' => gmdate( 'c' ), 'site_url' => site_url(), 'users' => $users, 'user_meta' => $user_meta, 'posts' => $post_data, 'tables' => $tables, 'options' => $options ];
    }

    private function import_data( array $data ): void {
        global $wpdb;
        $user_map = [];
        foreach ( $data['users'] as $row ) {
            $old_id = (int) $row['ID'];
            $user = ! empty( $row['user_email'] ) ? get_user_by( 'email', $row['user_email'] ) : false;
            $user = $user ?: get_user_by( 'login', $row['user_login'] );
            if ( ! $user ) {
                $new_id = wp_insert_user( [
                    'user_login' => sanitize_user( $row['user_login'], true ), 'user_pass' => wp_generate_password( 32, true, true ),
                    'user_email' => sanitize_email( $row['user_email'] ), 'user_url' => esc_url_raw( $row['user_url'] ),
                    'user_registered' => $row['user_registered'], 'display_name' => sanitize_text_field( $row['display_name'] ),
                ] );
                if ( is_wp_error( $new_id ) ) {
                    throw new RuntimeException( $new_id->get_error_message() );
                }
            } else {
                $new_id = $user->ID;
                wp_update_user( [ 'ID' => $new_id, 'display_name' => sanitize_text_field( $row['display_name'] ), 'user_url' => esc_url_raw( $row['user_url'] ) ] );
            }
            $user_map[ $old_id ] = (int) $new_id;
            foreach ( $data['user_meta'][ $old_id ] ?? [] as $meta ) {
                if ( ! $this->is_sensitive_meta( (string) $meta['meta_key'] ) ) {
                    update_user_meta( $new_id, $meta['meta_key'], maybe_unserialize( $meta['meta_value'] ) );
                }
            }
        }

        $post_map = [];
        foreach ( $data['posts'] as $row ) {
            $existing = get_posts( [ 'post_type' => $row['post_type'], 'post_status' => 'any', 'meta_key' => '_imao_import_source_id', 'meta_value' => (string) $row['source_id'], 'numberposts' => 1, 'fields' => 'ids' ] );
            $postarr = [ 'post_author' => $user_map[ (int) $row['post_author'] ] ?? get_current_user_id(), 'post_date' => $row['post_date'], 'post_content' => $row['post_content'], 'post_title' => $row['post_title'], 'post_excerpt' => $row['post_excerpt'], 'post_status' => $row['post_status'], 'comment_status' => $row['comment_status'], 'ping_status' => $row['ping_status'], 'post_name' => $row['post_name'], 'menu_order' => (int) $row['menu_order'], 'post_type' => $row['post_type'] ];
            if ( $existing ) { $postarr['ID'] = (int) $existing[0]; }
            $new_id = wp_insert_post( wp_slash( $postarr ), true );
            if ( is_wp_error( $new_id ) ) { throw new RuntimeException( $new_id->get_error_message() ); }
            $post_map[ (int) $row['source_id'] ] = (int) $new_id;
            update_post_meta( $new_id, '_imao_import_source_id', (string) $row['source_id'] );
            foreach ( $row['meta'] as $key => $values ) {
                if ( $key === '_imao_import_source_id' ) { continue; }
                delete_post_meta( $new_id, $key );
                foreach ( (array) $values as $value ) { add_post_meta( $new_id, $key, maybe_unserialize( $value ) ); }
            }
            foreach ( $row['terms'] as $taxonomy => $terms ) {
                $term_ids = [];
                foreach ( $terms as $term ) {
                    $found = term_exists( $term['slug'], $taxonomy );
                    if ( ! $found ) { $found = wp_insert_term( $term['name'], $taxonomy, [ 'slug' => $term['slug'] ] ); }
                    if ( ! is_wp_error( $found ) ) { $term_ids[] = (int) ( is_array( $found ) ? $found['term_id'] : $found ); }
                }
                wp_set_object_terms( $new_id, $term_ids, $taxonomy, false );
            }
        }

        foreach ( $data['tables'] as $suffix => $rows ) {
            if ( ! in_array( $suffix, self::TABLES, true ) ) { continue; }
            $table = $wpdb->prefix . $suffix;
            foreach ( $rows as $row ) {
                unset( $row['id'] );
                foreach ( [ 'user_id', 'assigned_by', 'deactivated_by', 'requested_by', 'decided_by' ] as $key ) {
                    if ( isset( $row[ $key ], $user_map[ (int) $row[ $key ] ] ) ) { $row[ $key ] = $user_map[ (int) $row[ $key ] ]; }
                }
                if ( isset( $row['competition_id'], $post_map[ (int) $row['competition_id'] ] ) ) { $row['competition_id'] = $post_map[ (int) $row['competition_id'] ]; }
                $wpdb->replace( $table, $row );
            }
        }
        foreach ( $data['options'] as $option ) {
            if ( strpos( $option['option_name'], 'imao_' ) === 0 ) { update_option( $option['option_name'], maybe_unserialize( $option['option_value'] ) ); }
        }
    }

    private function is_sensitive_meta( string $key ): bool {
        return in_array( $key, [ 'session_tokens', 'application_passwords', 'wp_user-settings', 'wp_user-settings-time' ], true )
            || (bool) preg_match( '/(?:^|_)(?:otp|secret|token|password|passkey)(?:_|$)/i', $key );
    }

    private function validate_payload( $data ): void {
        if ( ! is_array( $data ) || ( $data['format'] ?? '' ) !== self::FORMAT ) { throw new RuntimeException( 'قالب فایل پشتیبان معتبر نیست.' ); }
        foreach ( [ 'users', 'user_meta', 'posts', 'tables', 'options' ] as $key ) {
            if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) { throw new RuntimeException( 'بخش ' . $key . ' در فایل وجود ندارد.' ); }
        }
    }

    private function authorize( string $action ): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'دسترسی غیرمجاز.', 403 ); }
        check_admin_referer( $action );
    }

    private function redirect( string $status, string $message = '' ): void {
        $url = add_query_arg( [ 'page' => 'imao-plugin-settings', 'imao_transfer' => $status, 'imao_message' => $message ], admin_url( 'options-general.php' ) );
        wp_safe_redirect( $url );
        exit;
    }
}
