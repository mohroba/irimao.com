<?php

namespace IMAOCustom\Services;

use IMAOCustom\Helpers\CityMap;
use WP_Post;

class CompetitionCardTemplates {
    public const POST_TYPE = 'competition_card';
    public const META_SELECTED_TEMPLATE = '_imao_competition_card_template_id';

    /** @var array<int,array<string,string>> */
    private static array $context_stack = [];

    public function register(): void {
        add_action( 'init', [ $this, 'register_post_type' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'add_meta_boxes_' . self::POST_TYPE, [ $this, 'add_template_meta_boxes' ] );
        add_action( 'save_post_competition', [ $this, 'save_competition_template' ], 15, 2 );
        add_shortcode( 'imao_card_user', [ $this, 'user_shortcode' ] );
        add_shortcode( 'imao_card_competition', [ $this, 'competition_shortcode' ] );
        add_shortcode( 'imao_card_registration', [ $this, 'registration_shortcode' ] );
        add_shortcode( 'imao_card_qr', [ $this, 'qr_shortcode' ] );
        add_shortcode( 'imao_card_verify_url', [ $this, 'verify_url_shortcode' ] );
    }

    public function register_post_type(): void {
        $labels = [
            'name'          => 'قالب‌های کارت مسابقه',
            'singular_name' => 'قالب کارت مسابقه',
            'add_new_item'  => 'افزودن قالب کارت مسابقه',
            'edit_item'     => 'ویرایش قالب کارت مسابقه',
            'search_items'  => 'جستجوی قالب‌های کارت مسابقه',
            'menu_name'     => 'قالب کارت مسابقه',
        ];

        register_post_type( self::POST_TYPE, [
            'labels'              => $labels,
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => 'edit.php?post_type=competition',
            'exclude_from_search' => true,
            'has_archive'         => false,
            'rewrite'             => [ 'slug' => 'competition-cards' ],
            'supports'            => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ],
            'show_in_rest'        => true,
            'menu_icon'           => 'dashicons-id',
        ] );
    }

    public function add_meta_box(): void {
        add_meta_box( 'imao_competition_card_template', 'قالب کارت مسابقه', [ $this, 'render_competition_template_box' ], 'competition', 'side', 'default' );
    }

    public function add_template_meta_boxes(): void {
        add_meta_box( 'imao_competition_card_shortcodes', 'شورت‌کدهای کارت مسابقه', [ $this, 'render_shortcodes_box' ], self::POST_TYPE, 'side', 'high' );
    }

    public function render_shortcodes_box(): void {
        $shortcodes = [
            '[imao_card_user field="full_name"]'              => 'نام ورزشکار',
            '[imao_card_user field="national_id"]'            => 'کد ملی (هر کلید متای کاربر قابل استفاده است)',
            '[imao_card_user field="personal_photo"]'         => 'آدرس عکس پرسنلی',
            '[imao_card_competition field="title"]'           => 'عنوان مسابقه',
            '[imao_card_competition field="date_range"]'      => 'بازه تاریخ مسابقه',
            '[imao_card_competition field="competition_code"]' => 'جزئیات مسابقه (هر کلید متای مسابقه)',
            '[imao_card_registration field="weight"]'         => 'وزن یا دسته وزنی',
            '[imao_card_registration field="age_category"]'   => 'رده سنی',
            '[imao_card_qr]'                                    => 'تصویر QR استعلام',
            '[imao_card_verify_url]'                            => 'آدرس استعلام',
        ];

        echo '<p>این شورت‌کدها را در ویرایشگر، Elementor یا صفحه‌ساز خود قرار دهید. خروجی فیلدها فقط متن یا آدرس تصویر است.</p>';
        echo '<dl style="margin:0">';
        foreach ( $shortcodes as $shortcode => $description ) {
            echo '<dt><code style="display:block;direction:ltr;white-space:normal">' . esc_html( $shortcode ) . '</code></dt>';
            echo '<dd style="margin:3px 0 12px;color:#646970">' . esc_html( $description ) . '</dd>';
        }
        echo '</dl>';
    }

    public function render_competition_template_box( WP_Post $post ): void {
        wp_nonce_field( 'imao_save_competition_card_template_' . $post->ID, 'imao_competition_card_template_nonce' );
        $selected = self::selected_template_id( $post->ID );
        $templates = get_posts( [
            'post_type'      => self::POST_TYPE,
            'post_status'    => [ 'publish', 'draft', 'private' ],
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        echo '<p style="margin-top:0;color:#646970">اگر قالبی انتخاب نشود، کارت پیش‌فرض فعلی استفاده می‌شود.</p>';
        echo '<select name="imao_competition_card_template_id" style="width:100%">';
        echo '<option value="0"' . selected( 0, $selected, false ) . '>کارت پیش‌فرض افزونه</option>';
        foreach ( $templates as $template ) {
            if ( ! $template instanceof WP_Post ) {
                continue;
            }
            printf(
                '<option value="%d"%s>%s</option>',
                (int) $template->ID,
                selected( (int) $template->ID, $selected, false ),
                esc_html( $template->post_title )
            );
        }
        echo '</select>';
        echo '<p style="font-size:12px;color:#646970">در قالب‌های Elementor از شورت‌کدهای کارت برای جایگذاری اطلاعات ورزشکار و مسابقه استفاده کنید.</p>';
    }

    public function save_competition_template( int $competition_id, WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $competition_id ) ) return;
        $nonce = $_POST['imao_competition_card_template_nonce'] ?? '';
        if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'imao_save_competition_card_template_' . $competition_id ) ) return;
        $template_id = absint( $_POST['imao_competition_card_template_id'] ?? 0 );
        if ( $template_id > 0 && get_post_type( $template_id ) !== self::POST_TYPE ) {
            $template_id = 0;
        }
        if ( $template_id > 0 ) {
            update_post_meta( $competition_id, self::META_SELECTED_TEMPLATE, $template_id );
        } else {
            delete_post_meta( $competition_id, self::META_SELECTED_TEMPLATE );
        }
    }

    public static function selected_template_id( int $competition_id ): int {
        $template_id = (int) get_post_meta( $competition_id, self::META_SELECTED_TEMPLATE, true );
        return $template_id > 0 && get_post_type( $template_id ) === self::POST_TYPE ? $template_id : 0;
    }

    /** @param array<string,string> $data */
    public static function render_document( int $template_id, array $data ): string {
        $content = self::render_template_content( $template_id, $data );
        ob_start(); ?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>کارت مسابقه</title>
<style>*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:#202124;font-family:Tahoma,Arial,sans-serif}.toolbar{position:sticky;top:0;z-index:10000;padding:10px;text-align:center;background:#fff}.toolbar button{padding:8px 20px;border:0;border-radius:5px;background:#17134b;color:#fff;font:700 14px Tahoma;cursor:pointer}.imao-custom-card-page{background:#fff;margin:18px auto;max-width:100%;width:fit-content}@page{size:A4 portrait;margin:0}@media print{html,body{background:#fff;width:100%;height:100%}.toolbar{display:none}.imao-custom-card-page{margin:0;box-shadow:none;max-width:none;width:100%}}</style>
</head><body><div class="toolbar"><button type="button" onclick="window.print()">چاپ / ذخیره PDF</button></div><main class="imao-custom-card-page"><?php echo $content; ?></main><script>window.addEventListener('load',function(){if(!new URLSearchParams(location.search).has('preview'))window.setTimeout(function(){window.print()},250)});</script></body></html>
<?php return (string) ob_get_clean();
    }

    /** @param array<string,string> $data */
    private static function render_template_content( int $template_id, array $data ): string {
        self::$context_stack[] = $data;
        try {
            if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->frontend ) ) {
                $content = (string) \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $template_id, true );
                if ( trim( $content ) !== '' ) {
                    return $content;
                }
            }

            $post = get_post( $template_id );
            if ( $post instanceof WP_Post ) {
                return (string) apply_filters( 'the_content', $post->post_content );
            }
            return '';
        } finally {
            array_pop( self::$context_stack );
        }
    }

    public function user_shortcode( $atts = [] ): string {
        $atts = shortcode_atts( [ 'field' => 'display_name', 'user_id' => 0, 'default' => '' ], (array) $atts, 'imao_card_user' );
        $field = sanitize_key( (string) $atts['field'] );
        $user_id = (int) $atts['user_id'] ?: (int) $this->context_value( 'user_id' );
        $value = $this->user_field( $user_id, $field );
        return esc_html( $value !== '' ? $value : (string) $atts['default'] );
    }

    public function competition_shortcode( $atts = [] ): string {
        $atts = shortcode_atts( [ 'field' => 'title', 'competition_id' => 0, 'default' => '' ], (array) $atts, 'imao_card_competition' );
        $field = sanitize_key( (string) $atts['field'] );
        $competition_id = (int) $atts['competition_id'] ?: (int) $this->context_value( 'competition_id' );
        $value = $this->competition_field( $competition_id, $field );
        return esc_html( $value !== '' ? $value : (string) $atts['default'] );
    }

    public function registration_shortcode( $atts = [] ): string {
        $atts = shortcode_atts( [ 'field' => 'weight', 'default' => '' ], (array) $atts, 'imao_card_registration' );
        $field = sanitize_key( (string) $atts['field'] );
        $aliases = [
            'full_name' => 'name',
            'national_code' => 'national_id',
            'age_category' => 'age',
            'insurance_expiry' => 'insurance_date',
            'federation_expiry' => 'federation_membership_expiry',
        ];
        $value = $this->context_value( $aliases[ $field ] ?? $field );
        return esc_html( $value !== '' ? $value : (string) $atts['default'] );
    }

    public function qr_shortcode( $atts = [] ): string {
        $atts = shortcode_atts( [ 'alt' => 'QR استعلام اصالت کارت', 'class' => 'imao-card-qr' ], (array) $atts, 'imao_card_qr' );
        $src = $this->context_value( 'qr_data_uri' );
        if ( $src === '' ) {
            return '';
        }
        return '<img src="' . esc_attr( $src ) . '" alt="' . esc_attr( (string) $atts['alt'] ) . '" class="' . esc_attr( (string) $atts['class'] ) . '">';
    }

    public function verify_url_shortcode(): string {
        return esc_url( $this->context_value( 'verification_url' ) );
    }

    private function context_value( string $key ): string {
        $context = self::$context_stack ? self::$context_stack[ count( self::$context_stack ) - 1 ] : [];
        return isset( $context[ $key ] ) ? (string) $context[ $key ] : '';
    }

    private function user_field( int $user_id, string $field ): string {
        if ( ! $user_id ) {
            return '';
        }
        $user = get_userdata( $user_id );
        if ( $field === 'display_name' ) {
            return $user ? (string) $user->display_name : '';
        }
        if ( $field === 'email' || $field === 'user_email' ) {
            return $user ? (string) $user->user_email : '';
        }
        if ( $field === 'login' || $field === 'user_login' ) {
            return $user ? (string) $user->user_login : '';
        }
        if ( $field === 'url' || $field === 'user_url' ) {
            return $user ? (string) $user->user_url : '';
        }
        if ( $field === 'full_name' || $field === 'name' ) {
            $first = (string) get_user_meta( $user_id, 'first_name_fa', true );
            $last = (string) get_user_meta( $user_id, 'last_name_fa', true );
            return trim( $first . ' ' . $last ) ?: ( $user ? (string) $user->display_name : '' );
        }
        if ( $field === 'province' ) {
            $province_code = (string) get_user_meta( $user_id, 'residence_province', true );
            return CityMap::get_provinces()[ $province_code ] ?? $province_code;
        }
        if ( $field === 'profile_image' || $field === 'profile_photo' || $field === 'avatar' ) {
            $photo = (string) get_user_meta( $user_id, 'personal_photo', true );
            return $photo !== '' ? $photo : ( function_exists( 'get_avatar_url' ) ? (string) get_avatar_url( $user_id ) : '' );
        }
        return (string) get_user_meta( $user_id, $field, true );
    }

    private function competition_field( int $competition_id, string $field ): string {
        if ( ! $competition_id ) {
            return '';
        }
        if ( $field === 'title' || $field === 'name' ) {
            return (string) get_the_title( $competition_id );
        }
        if ( $field === 'date_range' || $field === 'competition_dates' ) {
            return CompetitionCards::format_competition_date_range(
                (string) get_post_meta( $competition_id, 'start_date', true ),
                (string) get_post_meta( $competition_id, 'end_date', true )
            );
        }
        if ( $field === 'url' || $field === 'permalink' ) {
            return (string) get_permalink( $competition_id );
        }
        return (string) get_post_meta( $competition_id, $field, true );
    }
}
