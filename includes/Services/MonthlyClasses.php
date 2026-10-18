<?php

namespace IMAOCustom\Services;

use IMAOCustom\Helpers\UserRoles;
use IMAOCustom\Helpers\Date;

/** Monthly class configuration and presentation; billing belongs to MonthlyTuition. */
class MonthlyClasses {
    public const POST_TYPE = 'imao_monthly_class';

    public function register(): void {
        add_action( 'init', [ $this, 'register_types' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_class' ], 10, 2 );
        add_action( 'admin_notices', [ $this, 'admin_notice' ] );
        add_action( 'admin_post_imao_submit_monthly_class', [ $this, 'submit_class' ] );
        add_shortcode( 'imao_class_submit', [ $this, 'submission_shortcode' ] );
        add_shortcode( 'imao_monthly_classes', [ $this, 'classes_shortcode' ] );
        add_shortcode( 'imao_class_list', [ $this, 'directory_shortcode' ] );
        add_filter( 'woocommerce_get_query_vars', [ $this, 'account_query_vars' ] );
        add_action( 'woocommerce_account_monthly-class-list_endpoint', [ $this, 'account_directory' ] );
        add_action( 'woocommerce_account_monthly-class-payments_endpoint', [ $this, 'account_payments' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'account_menu' ] );
        add_action( 'woocommerce_account_monthly-classes_endpoint', [ $this, 'account_classes' ] );
        add_action( 'woocommerce_account_monthly-class-submit_endpoint', [ $this, 'account_submit' ] );
    }

    public function register_types(): void {
        $capabilities = array_fill_keys( [
            'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts',
            'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts',
            'delete_published_posts', 'delete_others_posts', 'edit_private_posts',
            'edit_published_posts', 'create_posts',
        ], 'manage_options' );
        register_post_type( self::POST_TYPE, [
            'labels' => [ 'name' => 'کلاس‌های ماهانه', 'singular_name' => 'کلاس ماهانه', 'add_new_item' => 'افزودن کلاس ماهانه', 'edit_item' => 'ویرایش کلاس ماهانه' ],
            'public' => false,
            'show_ui' => true,
            'show_in_rest' => false,
            'supports' => [ 'title' ],
            'capabilities' => $capabilities,
            'map_meta_cap' => false,
            'menu_icon' => 'dashicons-welcome-learn-more',
        ] );
        add_rewrite_endpoint( 'monthly-classes', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'monthly-class-submit', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'monthly-class-list', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'monthly-class-payments', EP_ROOT | EP_PAGES );
        if ( get_option( 'imao_monthly_classes_rewrite_version' ) !== '2' ) {
            flush_rewrite_rules( false );
            update_option( 'imao_monthly_classes_rewrite_version', '2', false );
        }
    }

    public function add_meta_boxes(): void {
        add_meta_box( 'imao-monthly-class', 'تنظیمات کلاس و تأیید مدیر', [ $this, 'render_meta_box' ], self::POST_TYPE );
    }

    public function render_meta_box( $post ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        wp_nonce_field( 'imao_save_monthly_class_' . $post->ID, 'imao_monthly_class_nonce' );
        foreach ( [ '_class_coach_id' => 'شناسه مربی', '_class_club_id' => 'شناسه باشگاه', '_class_fee' => 'شهریه ماهانه (واحد پول فروشگاه)' ] as $key => $label ) {
            echo '<p><label>' . esc_html( $label ) . ' <input type="number" min="0" step="' . ( $key === '_class_fee' ? '0.01' : '1' ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( get_post_meta( $post->ID, $key, true ) ) . '" required></label></p>';
        }
        $shares = get_post_meta( $post->ID, '_class_shares', true );
        foreach ( [ 'referrer' => 'معرف', 'coach' => 'مربی', 'club' => 'باشگاه' ] as $key => $label ) {
            echo '<p><label>درصد سهم ' . esc_html( $label ) . ' <input type="number" min="0.01" max="99.99" step="0.01" name="_class_shares[' . esc_attr( $key ) . ']" value="' . esc_attr( is_array( $shares ) ? ( $shares[ $key ] ?? '' ) : '' ) . '" required></label></p>';
        }
        echo '<p>مجموع سهم‌ها باید دقیقاً ۱۰۰ درصد باشد. باشگاه باید تأییدشده و متعلق به مربی باشد.</p>';
        echo '<p><label><input type="checkbox" name="_class_approved" value="yes" ' . checked( get_post_meta( $post->ID, '_class_approved', true ), 'yes', false ) . '> تأیید کلاس برای پرداخت (با وضعیت انتشار)</label></p>';
        echo '<p>شناسه محصول: ' . esc_html( (string) absint( get_post_meta( $post->ID, '_class_product_id', true ) ) ) . '</p>';
        $audit = get_post_meta( $post->ID, '_class_config_audit', false );
        if ( $audit ) {
            echo '<details><summary>سوابق تغییر تنظیمات و تأیید کلاس</summary>';
            foreach ( array_reverse( $audit ) as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                echo '<pre style="white-space:pre-wrap;direction:rtl">' . esc_html( wp_json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) . '</pre>';
            }
            echo '</details>';
        }
    }

    /** Return normalized percentages or null; reject missing or unexpected recipients. */
    public static function validate_shares( $shares ): ?array {
        if ( ! is_array( $shares ) || count( $shares ) !== 3 ) {
            return null;
        }
        $result = [];
        $total = 0;
        foreach ( [ 'referrer', 'coach', 'club' ] as $key ) {
            $value = $shares[ $key ] ?? null;
            if ( ! is_scalar( $value ) || ! preg_match( '/^\d{1,3}(?:\.\d{1,2})?$/D', (string) $value ) ) {
                return null;
            }
            $points = (int) round( (float) $value * 100 );
            if ( $points <= 0 || $points >= 10000 ) {
                return null;
            }
            $result[ $key ] = $points / 100;
            $total += $points;
        }
        return $total === 10000 ? $result : null;
    }

    public static function club_belongs_to_coach( int $club_id, int $coach_id ): bool {
        $club = get_post( $club_id );
        return $coach_id > 0 && $club && $club->post_type === 'club_application'
            && $club->post_status === 'publish' && (int) $club->post_author === $coach_id;
    }

    public static function valid_coach( int $coach_id ): bool {
        return $coach_id > 0 && UserRoles::has( get_userdata( $coach_id ), 'coach' )
            && get_user_meta( $coach_id, 'identity_verified_professional', true ) === 'approved';
    }

    private static function input( string $key ): string {
        return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
    }

    private static function fee( string $value ): string {
        if ( ! preg_match( '/^\d+(?:\.\d+)?$/D', $value ) || ! is_finite( (float) $value ) || (float) $value <= 0 ) {
            return '';
        }
        $fee = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $value, wc_get_price_decimals() ) : $value;
        return (float) $fee > 0 ? $fee : '';
    }

    public function save_class( int $post_id, $post ): void {
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id )
            || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id )
            || ! wp_verify_nonce( self::input( 'imao_monthly_class_nonce' ), 'imao_save_monthly_class_' . $post_id ) ) {
            return;
        }
        $previous = self::configuration( $post_id );
        try {
            $this->persist_class( $post_id, $post );
        } finally {
            $this->audit_configuration( $post_id, $previous, 'admin_config' );
        }
    }

    /** Append separate records instead of overwriting an audit array. */
    private function audit_configuration( int $post_id, array $previous, string $reason ): void {
        $new = self::configuration( $post_id );
        if ( $previous !== $new ) {
            add_post_meta( $post_id, '_class_config_audit', [
                'actor_id' => get_current_user_id(), 'time' => current_time( 'mysql', true ),
                'reason' => $reason, 'previous' => $previous, 'new' => $new,
            ] );
        }
    }

    private static function configuration( int $post_id ): array {
        $configuration = [];
        foreach ( [ '_class_coach_id', '_class_club_id', '_class_fee', '_class_shares', '_class_product_id', '_class_approved' ] as $key ) {
            $configuration[ $key ] = get_post_meta( $post_id, $key, true );
        }
        return $configuration;
    }

    private function persist_class( int $post_id, $post ): void {
        $coach_id = absint( self::input( '_class_coach_id' ) );
        $club_id = absint( self::input( '_class_club_id' ) );
        $fee = self::fee( self::input( '_class_fee' ) );
        $shares = self::validate_shares( isset( $_POST['_class_shares'] ) ? wp_unslash( $_POST['_class_shares'] ) : null );
        // Revoke approval before validation so invalid edits cannot remain payable.
        update_post_meta( $post_id, '_class_approved', 'no' );
        if ( ! self::valid_coach( $coach_id ) || ! self::club_belongs_to_coach( $club_id, $coach_id ) || $fee === '' || $shares === null ) {
            $this->set_error( 'اطلاعات کلاس نامعتبر است؛ مربی، مالکیت باشگاه، شهریه و مجموع سهم‌ها را بررسی کنید.' );
            return;
        }
        foreach ( [ '_class_coach_id' => $coach_id, '_class_club_id' => $club_id, '_class_fee' => $fee, '_class_shares' => $shares ] as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }
        if ( self::input( '_class_approved' ) !== 'yes' || $post->post_status !== 'publish' ) {
            return;
        }
        if ( ! class_exists( '\WC_Product_Simple' ) ) {
            $this->set_error( 'برای تأیید کلاس، ووکامرس باید فعال باشد.' );
            return;
        }
        try {
            $product_id = absint( get_post_meta( $post_id, '_class_product_id', true ) );
            $product = $product_id ? wc_get_product( $product_id ) : null;
            if ( $product_id && ( ! $product || ! $product->is_type( 'simple' ) || (int) $product->get_meta( '_imao_monthly_class_id' ) !== $post_id ) ) {
                $this->set_error( 'ارتباط محصول کلاس معتبر نیست؛ پیش از تأیید آن را بررسی کنید.' );
                return;
            }
            $product = $product ?: new \WC_Product_Simple();
            $product->set_name( get_the_title( $post_id ) );
            $product->set_status( 'publish' );
            $product->set_catalog_visibility( 'hidden' );
            $product->set_virtual( true );
            $product->set_sold_individually( true );
            $product->set_regular_price( $fee );
            $product->set_sale_price( '' );
            $product->set_price( $fee );
            $product->update_meta_data( '_imao_monthly_class_id', $post_id );
            $saved_id = $product->save();
            if ( ! $saved_id ) {
                throw new \RuntimeException( 'Monthly class product was not saved.' );
            }
            update_post_meta( $post_id, '_class_product_id', $saved_id );
            update_post_meta( $post_id, '_class_approved', 'yes' );
        } catch ( \Throwable $exception ) {
            $this->set_error( 'ذخیره محصول کلاس انجام نشد؛ کلاس تأیید نشده است. دوباره تلاش کنید.' );
        }
    }

    private function set_error( string $message ): void {
        set_transient( 'imao_monthly_class_error_' . get_current_user_id(), $message, 120 );
    }

    public function admin_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $key = 'imao_monthly_class_error_' . get_current_user_id();
        $message = get_transient( $key );
        if ( $message ) {
            delete_transient( $key );
            echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
        }
    }

    private function coach_clubs(): array {
        return get_posts( [ 'post_type' => 'club_application', 'post_status' => 'publish', 'author' => get_current_user_id(), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
    }

    private function render_coach_classes( array $clubs ): void {
        $classes = get_posts( [ 'post_type' => self::POST_TYPE, 'post_status' => [ 'publish', 'pending', 'draft' ],
            'author' => get_current_user_id(), 'posts_per_page' => 100, 'orderby' => 'date', 'order' => 'DESC' ] );
        foreach ( $classes as $class ) {
            if ( (int) get_post_meta( $class->ID, '_class_coach_id', true ) !== get_current_user_id() ) {
                continue;
            }
            $status = $class->post_status === 'publish' && get_post_meta( $class->ID, '_class_approved', true ) === 'yes' ? 'تأییدشده' : 'در انتظار تأیید / غیرفعال';
            echo '<details dir="rtl"><summary>' . esc_html( $class->post_title . ' — ' . $status ) . '</summary>';
            if ( $clubs && in_array( $class->post_status, [ 'pending', 'publish' ], true ) ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="imao_submit_monthly_class"><input type="hidden" name="class_id" value="' . (int) $class->ID . '">';
                wp_nonce_field( 'imao_submit_monthly_class', 'imao_class_nonce' );
                wp_nonce_field( 'imao_edit_monthly_class_' . $class->ID, 'imao_edit_class_nonce' );
                echo '<p><label>نام کلاس <input name="class_title" maxlength="200" required value="' . esc_attr( $class->post_title ) . '"></label></p>';
                echo '<p><label>باشگاه <select name="class_club_id" required>';
                foreach ( $clubs as $club ) {
                    echo '<option value="' . (int) $club->ID . '" ' . selected( get_post_meta( $class->ID, '_class_club_id', true ), $club->ID, false ) . '>' . esc_html( $club->post_title ) . '</option>';
                }
                echo '</select></label></p><p><label>شهریه <input name="class_fee" type="number" min="0.01" step="0.01" required value="' . esc_attr( get_post_meta( $class->ID, '_class_fee', true ) ) . '"></label></p>';
                echo '<p>ویرایش کلاس نیازمند تأیید دوباره مدیر است.</p><button class="button" type="submit">ویرایش و ارسال برای بررسی</button></form>';
            }
            echo '</details>';
        }
    }

    public function submission_shortcode(): string {
        if ( ! is_user_logged_in() || ! self::valid_coach( get_current_user_id() ) ) {
            return '<p>ثبت کلاس فقط برای مربیان واردشده با هویت حرفه‌ای تأییدشده امکان‌پذیر است.</p>';
        }
        $clubs = $this->coach_clubs();
        ob_start();
        $this->render_coach_classes( $clubs );
        if ( ! $clubs ) {
            echo '<p>برای ثبت کلاس، ابتدا باید باشگاه تأییدشده‌ای به نام شما ثبت شده باشد.</p>';
            return (string) ob_get_clean();
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" dir="rtl">';
        echo '<input type="hidden" name="action" value="imao_submit_monthly_class">';
        wp_nonce_field( 'imao_submit_monthly_class', 'imao_class_nonce' );
        echo '<p><label>نام کلاس <input type="text" name="class_title" maxlength="200" required></label></p>';
        echo '<p><label>باشگاه <select name="class_club_id" required><option value="">انتخاب باشگاه</option>';
        foreach ( $clubs as $club ) {
            echo '<option value="' . esc_attr( $club->ID ) . '">' . esc_html( $club->post_title ) . '</option>';
        }
        echo '</select></label></p><p><label>شهریه ماهانه (واحد پول فروشگاه) <input type="number" name="class_fee" min="0.01" step="0.01" required></label></p>';
        echo '<p>کلاس پس از بررسی و تأیید مدیر برای شاگردان نمایش داده می‌شود.</p><button type="submit" class="button">ارسال برای بررسی</button></form>';
        return (string) ob_get_clean();
    }

    public function submit_class(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ! is_user_logged_in()
            || ! self::valid_coach( get_current_user_id() ) ) {
            wp_die( 'اجازه ثبت کلاس ندارید.', '', [ 'response' => 403 ] );
            return;
        }
        check_admin_referer( 'imao_submit_monthly_class', 'imao_class_nonce' );
        $coach_id = get_current_user_id();
        $class_id = absint( self::input( 'class_id' ) );
        if ( $class_id ) {
            $class = get_post( $class_id );
            if ( ! $class || $class->post_type !== self::POST_TYPE || (int) $class->post_author !== $coach_id
                || (int) get_post_meta( $class_id, '_class_coach_id', true ) !== $coach_id
                || ! in_array( $class->post_status, [ 'pending', 'publish' ], true ) ) {
                wp_die( 'اجازه ویرایش این کلاس را ندارید.', '', [ 'response' => 403 ] );
                return;
            }
            check_admin_referer( 'imao_edit_monthly_class_' . $class_id, 'imao_edit_class_nonce' );
        }
        $club_id = absint( self::input( 'class_club_id' ) );
        $fee = self::fee( self::input( 'class_fee' ) );
        $title = self::input( 'class_title' );
        if ( $title === '' || $fee === '' || ! self::club_belongs_to_coach( $club_id, $coach_id ) ) {
            wp_die( 'نام کلاس، شهریه و باشگاه متعلق به خود را به‌درستی وارد کنید.', '', [ 'response' => 400 ] );
            return;
        }
        $previous = $class_id ? self::configuration( $class_id ) : [];
        // Revoke first, so any failed update also leaves the class unavailable for payment.
        if ( $class_id ) {
            update_post_meta( $class_id, '_class_approved', 'no' );
        }
        $data = [
            'post_type' => self::POST_TYPE, 'post_title' => $title, 'post_status' => 'pending', 'post_author' => $coach_id,
            'meta_input' => [ '_class_coach_id' => $coach_id, '_class_club_id' => $club_id, '_class_fee' => $fee, '_class_approved' => 'no' ],
        ];
        if ( $class_id ) {
            $data['ID'] = $class_id;
        }
        $id = wp_insert_post( $data, true );
        if ( $class_id ) {
            $this->audit_configuration( $class_id, $previous, 'coach_edit_requires_reapproval' );
        } elseif ( ! is_wp_error( $id ) && $id ) {
            $this->audit_configuration( (int) $id, [], 'coach_submit' );
        }
        if ( is_wp_error( $id ) || ! $id ) {
            wp_die( 'ثبت کلاس انجام نشد. دوباره تلاش کنید.', '', [ 'response' => 500 ] );
            return;
        }
        wp_die( 'کلاس ثبت شد و در انتظار تأیید مدیر است.', 'ثبت کلاس', [ 'response' => 200, 'back_link' => true ] );
    }

    public function classes_shortcode(): string {
        if ( ! is_user_logged_in() ) {
            return '<p>برای مشاهده کلاس‌ها وارد حساب کاربری شوید.</p>';
        }
        $user_id = get_current_user_id();
        $coach_id = absint( get_user_meta( $user_id, 'coach_id', true ) );
        if ( ! $coach_id ) {
            return '<p>هنوز مربی شما مشخص نشده است.</p>';
        }
        $page = isset( $_GET['monthly_class_page'] ) && is_scalar( $_GET['monthly_class_page'] ) ? max( 1, absint( $_GET['monthly_class_page'] ) ) : 1;
        $query = new \WP_Query( [
            'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => 20, 'paged' => $page,
            'orderby' => 'title', 'order' => 'ASC',
            'meta_query' => [
                [ 'key' => '_class_coach_id', 'value' => $coach_id, 'compare' => '=', 'type' => 'NUMERIC' ],
                [ 'key' => '_class_approved', 'value' => 'yes' ],
            ],
        ] );
        ob_start();
        echo '<div dir="rtl" class="imao-monthly-classes">';
        if ( ! $query->posts ) {
            echo '<p>کلاس ماهانه تأییدشده‌ای برای مربی شما وجود ندارد.</p>';
        }
        foreach ( $query->posts as $class ) {
            $id = (int) $class->ID;
            $fee = get_post_meta( $id, '_class_fee', true );
            echo '<section><h3>' . esc_html( $class->post_title ) . '</h3><p>باشگاه: ' . esc_html( get_the_title( (int) get_post_meta( $id, '_class_club_id', true ) ) ) . '</p>';
            echo '<p>شهریه ماهانه: ' . ( function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $fee ) ) : esc_html( $fee ) ) . '</p>';
            if ( class_exists( MonthlyTuition::class ) ) {
                $due = MonthlyTuition::due_date( $user_id, $id );
                $days = MonthlyTuition::days_remaining( $user_id, $id );
                echo '<p>سررسید: ' . esc_html( $due !== '' ? Date::to_jalali( $due, 'Y/m/d' ) : 'هنوز پرداخت نشده' ) . '</p>';
                if ( $days !== null ) {
                    echo '<p>' . esc_html( $days >= 0 ? 'روزهای باقی‌مانده: ' . $days : 'روزهای گذشته از سررسید: ' . abs( $days ) ) . '</p>';
                }
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="imao_pay_monthly_class"><input type="hidden" name="class_id" value="' . esc_attr( $id ) . '">';
                wp_nonce_field( 'imao_pay_monthly_class_' . $id, 'imao_monthly_class_nonce' );
                echo '<button class="button" type="submit">پرداخت شهریه ماهانه</button></form>';
            } else {
                echo '<p>سامانه پرداخت شهریه در حال آماده‌سازی است.</p>';
            }
            echo '</section>';
        }
        for ( $number = 1; $number <= (int) $query->max_num_pages; $number++ ) {
            echo '<a aria-label="' . esc_attr( 'صفحه ' . $number ) . '" href="' . esc_url( add_query_arg( 'monthly_class_page', $number ) ) . '">' . esc_html( (string) $number ) . '</a> ';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    /** Only known scalar filters may override shortcode defaults. */
    public static function directory_filters( array $attributes, array $request ): array {
        $defaults = [ 'province' => '', 'city' => '', 'coach_id' => 0, 'club_id' => 0, 'search' => '', 'per_page' => 20, 'order' => 'ASC', 'page' => 1 ];
        $filters = [];
        foreach ( $defaults as $key => $default ) {
            $value = $attributes[ $key ] ?? $default;
            $input = $request[ 'imao_class_list_' . $key ] ?? null;
            if ( is_scalar( $input ) ) {
                $value = wp_unslash( (string) $input );
            }
            $value = is_scalar( $value ) ? (string) $value : (string) $default;
            if ( in_array( $key, [ 'coach_id', 'club_id', 'per_page', 'page' ], true ) ) {
                $filters[ $key ] = ctype_digit( $value ) ? (int) $value : (int) $default;
            } else {
                $filters[ $key ] = sanitize_text_field( $value );
            }
        }
        $filters['per_page'] = max( 1, min( 100, $filters['per_page'] ) );
        $filters['page'] = max( 1, $filters['page'] );
        $filters['order'] = strtoupper( $filters['order'] ) === 'DESC' ? 'DESC' : 'ASC';
        return $filters;
    }

    /** Club IDs are resolved separately because geographic metadata belongs to clubs. */
    public static function directory_query_args( array $filters, array $club_ids = [] ): array {
        $args = [
            'post_type' => self::POST_TYPE, 'post_status' => 'publish',
            'posts_per_page' => $filters['per_page'], 'paged' => $filters['page'],
            'orderby' => [ 'title' => $filters['order'], 'ID' => $filters['order'] ],
            's' => $filters['search'],
            'meta_query' => [ 'relation' => 'AND', [ 'key' => '_class_approved', 'value' => 'yes', 'compare' => '=' ] ],
        ];
        foreach ( [ 'coach_id', 'club_id' ] as $key ) {
            if ( $filters[ $key ] > 0 ) {
                $args['meta_query'][] = [ 'key' => '_class_' . $key, 'value' => $filters[ $key ], 'compare' => '=', 'type' => 'NUMERIC' ];
            }
        }
        if ( $filters['province'] !== '' || $filters['city'] !== '' ) {
            // An empty IN list must never broaden a geographic search.
            $args['meta_query'][] = [ 'key' => '_class_club_id', 'value' => $club_ids ?: [ 0 ], 'compare' => 'IN', 'type' => 'NUMERIC' ];
        }
        return $args;
    }

    public function directory_shortcode( $attributes = [] ): string {
        $filters = self::directory_filters( is_array( $attributes ) ? $attributes : [], $_GET );
        $club_ids = [];
        if ( $filters['province'] !== '' || $filters['city'] !== '' ) {
            $meta = [ 'relation' => 'AND' ];
            foreach ( [ 'province', 'city' ] as $key ) {
                if ( $filters[ $key ] !== '' ) {
                    $meta[] = [ 'key' => 'club_' . $key, 'value' => $filters[ $key ], 'compare' => '=' ];
                }
            }
            $club_ids = get_posts( [ 'post_type' => 'club_application', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => $meta ] );
        }
        $query = new \WP_Query( self::directory_query_args( $filters, $club_ids ) );
        $provinces = \IMAOCustom\Helpers\CityMap::get_provinces();
        $selected_coach = is_user_logged_in() ? (int) get_user_meta( get_current_user_id(), 'coach_id', true ) : 0;
        // A relative, escaped URL cannot inherit an attacker-supplied host.
        $base = remove_query_arg( array_map( static function ( $key ) { return 'imao_class_list_' . $key; }, array_keys( $filters ) ) );
        ob_start();
        echo '<div dir="rtl" class="imao-class-list"><form method="get" action="' . esc_url( $base ) . '">';
        // Preserve page routing query arguments, including plain permalink installations.
        $routing = wp_parse_url( $base, PHP_URL_QUERY );
        parse_str( (string) $routing, $routing_args );
        foreach ( $routing_args as $key => $value ) {
            if ( is_scalar( $value ) ) {
                echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
            }
        }
        echo '<label>استان <select name="imao_class_list_province"><option value="">همه استان‌ها</option>';
        foreach ( $provinces as $code => $name ) {
            echo '<option value="' . esc_attr( $code ) . '" ' . selected( $filters['province'], $code, false ) . '>' . esc_html( $name ) . '</option>';
        }
        echo '</select></label>';
        $city_list_id = wp_unique_id( 'imao-class-cities-' );
        echo '<label>شهر <input type="text" name="imao_class_list_city" list="' . esc_attr( $city_list_id ) . '" value="' . esc_attr( $filters['city'] ) . '"></label><datalist id="' . esc_attr( $city_list_id ) . '">';
        foreach ( \IMAOCustom\Helpers\CityMap::get_cities( $filters['province'] ) as $city ) {
            echo '<option value="' . esc_attr( $city ) . '"></option>';
        }
        echo '</datalist>';
        $option_queries = self::directory_option_queries();
        $coaches = get_users( $option_queries['coaches'] );
        $clubs = get_posts( $option_queries['clubs'] );
        foreach ( [ 'coach_id' => [ 'مربی', 'همه مربیان', $coaches ], 'club_id' => [ 'باشگاه', 'همه باشگاه‌ها', $clubs ] ] as $key => $field ) {
            echo '<label>' . esc_html( $field[0] ) . ' <select name="imao_class_list_' . esc_attr( $key ) . '"><option value="0">' . esc_html( $field[1] ) . '</option>';
            foreach ( $field[2] as $option ) {
                $name = $key === 'coach_id' ? $option->display_name : $option->post_title;
                echo '<option value="' . esc_attr( $option->ID ) . '" ' . selected( $filters[ $key ], $option->ID, false ) . '>' . esc_html( $name ) . '</option>';
            }
            echo '</select></label>';
        }
        echo '<label>جستجو <input type="search" name="imao_class_list_search" value="' . esc_attr( $filters['search'] ) . '"></label>';
        foreach ( [ 'per_page', 'order' ] as $key ) {
            echo '<input type="hidden" name="imao_class_list_' . esc_attr( $key ) . '" value="' . esc_attr( $filters[ $key ] ) . '">';
        }
        echo '<button type="submit" class="button">فیلتر کلاس‌ها</button></form>';
        if ( ! $query->posts ) {
            echo '<p>کلاس تأییدشده‌ای یافت نشد.</p>';
        }
        foreach ( $query->posts as $class ) {
            $id = (int) $class->ID;
            $coach_id = (int) get_post_meta( $id, '_class_coach_id', true );
            $coach = get_userdata( $coach_id );
            $club_id = (int) get_post_meta( $id, '_class_club_id', true );
            $province = (string) get_post_meta( $club_id, 'club_province', true );
            $fee = get_post_meta( $id, '_class_fee', true );
            echo '<section><h3>' . esc_html( $class->post_title ) . '</h3>';
            foreach ( [ 'مربی' => $coach ? $coach->display_name : '', 'باشگاه' => $club_id > 0 ? get_the_title( $club_id ) : '', 'استان' => $provinces[ $province ] ?? $province, 'شهر' => get_post_meta( $club_id, 'club_city', true ) ] as $label => $value ) {
                echo '<p>' . esc_html( $label . ': ' . $value ) . '</p>';
            }
            echo '<p>شهریه ماهانه: ' . ( function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $fee ) ) : esc_html( $fee ) ) . '</p>';
            if ( $selected_coach > 0 && $selected_coach === $coach_id && class_exists( MonthlyTuition::class ) ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="imao_pay_monthly_class"><input type="hidden" name="class_id" value="' . esc_attr( $id ) . '">';
                wp_nonce_field( 'imao_pay_monthly_class_' . $id, 'imao_monthly_class_nonce' );
                echo '<button type="submit" class="button">پرداخت شهریه ماهانه</button></form>';
            }
            echo '</section>';
        }
        $args = [];
        foreach ( $filters as $key => $value ) {
            $args[ 'imao_class_list_' . $key ] = $value;
        }
        echo wp_kses_post( paginate_links( [ 'base' => str_replace( '999999999', '%#%', esc_url_raw( add_query_arg( array_merge( $args, [ 'imao_class_list_page' => 999999999 ] ), $base ) ) ), 'format' => '', 'current' => $filters['page'], 'total' => (int) $query->max_num_pages, 'add_args' => false ] ) );
        echo '</div>';
        return (string) ob_get_clean();
    }

    /** Public filter choices include only professionally approved coaches and published clubs. */
    public static function directory_option_queries(): array {
        return [
            'coaches' => [
                'role' => 'coach', 'meta_key' => 'identity_verified_professional', 'meta_value' => 'approved',
                'orderby' => 'display_name', 'order' => 'ASC', 'fields' => [ 'ID', 'display_name' ],
            ],
            'clubs' => [
                'post_type' => 'club_application', 'post_status' => 'publish',
                'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC',
            ],
        ];
    }

    public function account_query_vars( array $vars ): array {
        foreach ( [ 'monthly-classes', 'monthly-class-submit', 'monthly-class-list', 'monthly-class-payments' ] as $endpoint ) {
            $vars[ $endpoint ] = $endpoint;
        }
        return $vars;
    }

    public function account_directory(): void {
        echo $this->directory_shortcode();
    }

    public function account_payments(): void {
        echo ( new MonthlyTuition() )->payments_shortcode();
    }

    public function account_menu( array $items ): array {
        if ( is_user_logged_in() ) {
            $logout = $items['customer-logout'] ?? null;
            unset( $items['customer-logout'] );
            $items['monthly-classes'] = 'کلاس‌های ماهانه';
            $items['monthly-class-list'] = 'همه کلاس‌ها';
            $items['monthly-class-payments'] = 'سوابق شهریه کلاس‌ها';
            if ( self::valid_coach( get_current_user_id() ) ) {
                $items['monthly-class-submit'] = 'ثبت کلاس ماهانه';
            }
            if ( $logout !== null ) {
                $items['customer-logout'] = $logout;
            }
        }
        return $items;
    }

    public function account_classes(): void {
        echo $this->classes_shortcode();
        echo ( new MonthlyTuition() )->payments_shortcode();
    }

    public function account_submit(): void {
        echo $this->submission_shortcode();
    }
}
