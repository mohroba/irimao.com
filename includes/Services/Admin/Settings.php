<?php

namespace IMAOCustom\Services\Admin;

class Settings
{
    public const OPTION_ADD_TO_CART_MESSAGE = 'imao_enable_add_to_cart_message';
    public const OPTION_SHOW_COMPETITION_CARD_BUTTON = 'imao_show_competition_card_button';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('init', [$this, 'configure_add_to_cart_message'], PHP_INT_MAX);
        add_action('wp_loaded', [$this, 'configure_add_to_cart_message'], PHP_INT_MAX);
    }

    public function add_menu(): void
    {
        add_options_page(
            'تنظیمات افزونه IMAO',
            'افزونه IMAO',
            'manage_options',
            'imao-plugin-settings',
            [$this, 'render_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'imao_plugin_settings',
            self::OPTION_ADD_TO_CART_MESSAGE,
            [
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => [$this, 'sanitize_checkbox'],
            ]
        );
        register_setting(
            'imao_plugin_settings',
            self::OPTION_SHOW_COMPETITION_CARD_BUTTON,
            [
                'type'              => 'boolean',
                'default'           => true,
                'sanitize_callback' => [$this, 'sanitize_checkbox'],
            ]
        );

        add_settings_section(
            'imao_woocommerce_messages',
            'پیام‌های ووکامرس',
            '__return_false',
            'imao-plugin-settings'
        );

        add_settings_field(
            self::OPTION_ADD_TO_CART_MESSAGE,
            'پیام افزودن به سبد خرید',
            [$this, 'render_add_to_cart_field'],
            'imao-plugin-settings',
            'imao_woocommerce_messages'
        );

        add_settings_section(
            'imao_competition_cards',
            'کارت مسابقه',
            '__return_false',
            'imao-plugin-settings'
        );

        add_settings_field(
            self::OPTION_SHOW_COMPETITION_CARD_BUTTON,
            'دکمه دانلود / چاپ کارت',
            [$this, 'render_competition_card_button_field'],
            'imao-plugin-settings',
            'imao_competition_cards'
        );
    }

    public function sanitize_checkbox($value): string
    {
        return !empty($value) ? '1' : '0';
    }

    public function is_add_to_cart_message_enabled(): bool
    {
        return get_option(self::OPTION_ADD_TO_CART_MESSAGE, '0') === '1';
    }

    public function is_competition_card_button_visible(): bool
    {
        return get_option(self::OPTION_SHOW_COMPETITION_CARD_BUTTON, '1') === '1';
    }

    public function configure_add_to_cart_message(): void
    {
        if ($this->is_add_to_cart_message_enabled()) {
            remove_filter('wc_add_to_cart_message_html', '__return_null');
            remove_filter('wc_add_to_cart_message_html', '__return_null', 10);
            remove_filter('wc_add_to_cart_message_html', [$this, 'disable_add_to_cart_message'], PHP_INT_MAX);
            return;
        }

        if (!has_filter('wc_add_to_cart_message_html', [$this, 'disable_add_to_cart_message'])) {
            add_filter('wc_add_to_cart_message_html', [$this, 'disable_add_to_cart_message'], PHP_INT_MAX, 3);
        }
    }

    public function disable_add_to_cart_message($message, $products = [], $show_qty = false)
    {
        return null;
    }

    public function render_add_to_cart_field(): void
    {
        $enabled = $this->is_add_to_cart_message_enabled();
        echo '<label for="' . esc_attr(self::OPTION_ADD_TO_CART_MESSAGE) . '">';
        echo '<input type="checkbox" id="' . esc_attr(self::OPTION_ADD_TO_CART_MESSAGE) . '" name="' . esc_attr(self::OPTION_ADD_TO_CART_MESSAGE) . '" value="1" ' . checked($enabled, true, false) . '>';
        echo ' نمایش پیام استاندارد ووکامرس پس از افزودن محصول به سبد خرید';
        echo '</label>';
        echo '<p class="description">این گزینه به‌صورت پیش‌فرض غیرفعال است.</p>';
    }

    public function render_competition_card_button_field(): void
    {
        $visible = $this->is_competition_card_button_visible();
        echo '<input type="hidden" name="' . esc_attr(self::OPTION_SHOW_COMPETITION_CARD_BUTTON) . '" value="0">';
        echo '<label for="' . esc_attr(self::OPTION_SHOW_COMPETITION_CARD_BUTTON) . '">';
        echo '<input type="checkbox" id="' . esc_attr(self::OPTION_SHOW_COMPETITION_CARD_BUTTON) . '" name="' . esc_attr(self::OPTION_SHOW_COMPETITION_CARD_BUTTON) . '" value="1" ' . checked($visible, true, false) . '>';
        echo ' نمایش دکمهٔ «دانلود / چاپ کارت» در پنل ورزشکار';
        echo '</label>';
        echo '<p class="description">این گزینه به‌صورت پیش‌فرض فعال است و فقط نمایش دکمه را کنترل می‌کند؛ صدور کارت و لینک اعتبارسنجی حذف نمی‌شود.</p>';
    }

    /**
     * @return array<int,array{tag:string,description:string,params:string,return:string,example:string}>
     */
    public static function shortcode_catalog(): array
    {
        return [
            [ 'tag' => 'crm_competitions_list', 'description' => 'فهرست مسابقات قابل ثبت‌نام', 'params' => 'بدون پارامتر', 'return' => 'HTML جدول', 'example' => '[crm_competitions_list]' ],
            [ 'tag' => 'crm_competition_details', 'description' => 'جزئیات یک مسابقه و فرم ثبت‌نام', 'params' => 'id: شناسه مسابقه؛ در صورت خالی بودن از competition_id استفاده می‌شود', 'return' => 'HTML فرم و جزئیات', 'example' => '[crm_competition_details id="10963"]' ],
            [ 'tag' => 'crm_user_competitions', 'description' => 'مسابقات ثبت‌نام‌شده کاربر جاری', 'params' => 'بدون پارامتر', 'return' => 'HTML جدول', 'example' => '[crm_user_competitions]' ],
            [ 'tag' => 'crm_competition_bracket', 'description' => 'نمایش جدول حذفی مسابقه', 'params' => 'competition_id: شناسه مسابقه', 'return' => 'HTML جدول تعاملی', 'example' => '[crm_competition_bracket competition_id="10963"]' ],
            [ 'tag' => 'crm_competition_bracket_button', 'description' => 'لینک مشاهده جدول حذفی', 'params' => 'competition_id: شناسه مسابقه؛ label: متن لینک', 'return' => 'HTML لینک', 'example' => '[crm_competition_bracket_button competition_id="10963" label="جدول مسابقات"]' ],
            [ 'tag' => 'crm_competition_rankings', 'description' => 'رده‌بندی مسابقات', 'params' => 'competition: شناسه یا فهرست شناسه‌ها؛ weight: دسته وزنی؛ gender: جنسیت؛ order: ترتیب', 'return' => 'HTML جدول', 'example' => '[crm_competition_rankings competition="10963" weight="70" gender="male"]' ],
            [ 'tag' => 'crm_my_rankings', 'description' => 'رده‌بندی‌های کاربر جاری', 'params' => 'بدون پارامتر', 'return' => 'HTML جدول', 'example' => '[crm_my_rankings]' ],
            [ 'tag' => 'crm_rankings_overview', 'description' => 'نمای کلی رده‌بندی‌ها', 'params' => 'gender: جنسیت؛ limit: تعداد؛ سایر فیلترهای رده‌بندی', 'return' => 'HTML جدول', 'example' => '[crm_rankings_overview gender="female" limit="20"]' ],
            [ 'tag' => 'crm_courses_list', 'description' => 'فهرست دوره‌ها', 'params' => 'بدون پارامتر', 'return' => 'HTML فهرست', 'example' => '[crm_courses_list]' ],
            [ 'tag' => 'crm_course_details', 'description' => 'جزئیات یک دوره', 'params' => 'id: شناسه دوره', 'return' => 'HTML جزئیات', 'example' => '[crm_course_details id="123"]' ],
            [ 'tag' => 'crm_user_courses', 'description' => 'دوره‌های کاربر جاری', 'params' => 'بدون پارامتر', 'return' => 'HTML جدول', 'example' => '[crm_user_courses]' ],
            [ 'tag' => 'crm_wallet', 'description' => 'کیف پول کاربر', 'params' => 'بدون پارامتر', 'return' => 'HTML پنل', 'example' => '[crm_wallet]' ],
            [ 'tag' => 'crm_wallet_income', 'description' => 'درآمدهای کیف پول', 'params' => 'بدون پارامتر', 'return' => 'HTML جدول', 'example' => '[crm_wallet_income]' ],
            [ 'tag' => 'crm_wallet_payments', 'description' => 'پرداخت‌های کیف پول', 'params' => 'بدون پارامتر', 'return' => 'HTML جدول', 'example' => '[crm_wallet_payments]' ],
            [ 'tag' => 'imao_card_user', 'description' => 'یک مقدار از اطلاعات کاربر کارت', 'params' => 'field: full_name، display_name، national_id، personal_photo، profile_image، email یا هر کلید متای کاربر؛ user_id اختیاری؛ default مقدار جایگزین', 'return' => 'متن یا URL تصویر', 'example' => '[imao_card_user field="full_name"]' ],
            [ 'tag' => 'imao_card_competition', 'description' => 'یک مقدار از اطلاعات مسابقه کارت', 'params' => 'field: title، date_range، competition_code، url یا هر کلید متای مسابقه؛ competition_id اختیاری؛ default مقدار جایگزین', 'return' => 'متن یا URL', 'example' => '[imao_card_competition field="date_range"]' ],
            [ 'tag' => 'imao_card_registration', 'description' => 'یک مقدار از ثبت‌نام جاری کارت', 'params' => 'field: weight، age_category، insurance_expiry، federation_expiry یا کلید متای سفارش', 'return' => 'متن', 'example' => '[imao_card_registration field="weight"]' ],
            [ 'tag' => 'imao_card_qr', 'description' => 'QR استعلام اصالت کارت', 'params' => 'alt: متن جایگزین؛ class: کلاس CSS', 'return' => 'HTML تصویر QR', 'example' => '[imao_card_qr class="my-card-qr"]' ],
            [ 'tag' => 'imao_card_verify_url', 'description' => 'آدرس استعلام اصالت کارت', 'params' => 'بدون پارامتر', 'return' => 'URL', 'example' => '[imao_card_verify_url]' ],
            [ 'tag' => 'crm_approved_coaches', 'description' => 'فهرست مربیان تأییدشده', 'params' => 'بدون پارامتر', 'return' => 'HTML فهرست', 'example' => '[crm_approved_coaches]' ],
            [ 'tag' => 'crm_approved_clubs', 'description' => 'فهرست باشگاه‌های تأییدشده', 'params' => 'بدون پارامتر', 'return' => 'HTML فهرست', 'example' => '[crm_approved_clubs]' ],
            [ 'tag' => 'crm_change_password', 'description' => 'فرم تغییر رمز عبور', 'params' => 'بدون پارامتر', 'return' => 'HTML فرم', 'example' => '[crm_change_password]' ],
            [ 'tag' => 'club_register', 'description' => 'فرم ثبت یا تکمیل باشگاه', 'params' => 'بدون پارامتر', 'return' => 'HTML فرم', 'example' => '[club_register]' ],
            [ 'tag' => 'crm_coach_students', 'description' => 'فهرست شاگردان مربی جاری', 'params' => 'بدون پارامتر', 'return' => 'HTML جدول', 'example' => '[crm_coach_students]' ],
            [ 'tag' => 'crm_edit_basic_info', 'description' => 'فرم ویرایش اطلاعات پایه', 'params' => 'بدون پارامتر', 'return' => 'HTML فرم', 'example' => '[crm_edit_basic_info]' ],
            [ 'tag' => 'crm_identity_professional', 'description' => 'فرم هویت حرفه‌ای', 'params' => 'بدون پارامتر', 'return' => 'HTML فرم', 'example' => '[crm_identity_professional]' ],
            [ 'tag' => 'crm_self_declaration', 'description' => 'فرم خوداظهاری', 'params' => 'بدون پارامتر', 'return' => 'HTML فرم', 'example' => '[crm_self_declaration]' ],
            [ 'tag' => 'crm_self_declarations_list', 'description' => 'فهرست خوداظهاری‌های کاربر', 'params' => 'بدون پارامتر', 'return' => 'HTML جدول', 'example' => '[crm_self_declarations_list]' ],
            [ 'tag' => 'crm_smartcard_issue', 'description' => 'فرم صدور کارت هوشمند', 'params' => 'بدون پارامتر', 'return' => 'HTML فرم', 'example' => '[crm_smartcard_issue]' ],
            [ 'tag' => 'crm_style_committe_form', 'description' => 'فرم درخواست کمیته سبک', 'params' => 'بدون پارامتر', 'return' => 'HTML فرم', 'example' => '[crm_style_committe_form]' ],
            [ 'tag' => 'crm_city_representatives', 'description' => 'نمایندگان شهرستان', 'params' => 'پارامترها بسته به صفحه و تنظیمات نمایندگان', 'return' => 'HTML فهرست', 'example' => '[crm_city_representatives]' ],
            [ 'tag' => 'crm_city_rep_requests', 'description' => 'درخواست‌های نمایندگی شهرستان', 'params' => 'بدون پارامتر', 'return' => 'HTML جدول', 'example' => '[crm_city_rep_requests]' ],
        ];
    }

    private function current_tab(): string
    {
        $tab = sanitize_key($_GET['tab'] ?? 'general');
        return in_array($tab, ['general', 'shortcodes'], true) ? $tab : 'general';
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap" dir="rtl">
            <h1>تنظیمات افزونه IMAO</h1>
            <nav class="nav-tab-wrapper" style="margin-bottom:20px">
                <a class="nav-tab <?php echo $this->current_tab() === 'general' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('options-general.php?page=imao-plugin-settings&tab=general')); ?>">تنظیمات عمومی</a>
                <a class="nav-tab <?php echo $this->current_tab() === 'shortcodes' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('options-general.php?page=imao-plugin-settings&tab=shortcodes')); ?>">کاوشگر شورت‌کدها</a>
            </nav>
            <?php if ($this->current_tab() === 'shortcodes') : ?>
                <?php $this->render_shortcode_explorer(); ?>
            <?php else : ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('imao_plugin_settings');
                    do_settings_sections('imao-plugin-settings');
                    submit_button();
                    ?>
                </form>
                <?php do_action('imao_plugin_settings_after_sections'); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_shortcode_explorer(): void
    {
        $catalog = self::shortcode_catalog();
        ?>
        <p>در این بخش همهٔ شورت‌کدهای افزونه، پارامترهای قابل استفاده و نوع خروجی آن‌ها را می‌بینید. شورت‌کدهای کارت مسابقه در قالب‌های Elementor نیز قابل استفاده‌اند.</p>
        <p><input type="search" id="imao-shortcode-search" placeholder="جستجو در نام، توضیح یا پارامترها..." style="min-width:320px"></p>
        <table class="widefat striped" id="imao-shortcode-catalog">
            <thead><tr><th>شورت‌کد</th><th>کاربرد</th><th>پارامترها</th><th>نوع خروجی</th><th>نمونه استفاده</th></tr></thead>
            <tbody>
            <?php foreach ($catalog as $row) : ?>
                <tr>
                    <td><code dir="ltr"><?php echo esc_html('[' . $row['tag'] . ']'); ?></code></td>
                    <td><?php echo esc_html($row['description']); ?></td>
                    <td><?php echo esc_html($row['params']); ?></td>
                    <td><?php echo esc_html($row['return']); ?></td>
                    <td><code dir="ltr"><?php echo esc_html($row['example']); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <script>
        jQuery(function ($) {
            $('#imao-shortcode-search').on('input', function () {
                const query = $(this).val().toString().toLowerCase();
                $('#imao-shortcode-catalog tbody tr').each(function () {
                    $(this).toggle($(this).text().toLowerCase().indexOf(query) !== -1);
                });
            });
        });
        </script>
        <?php
    }
}
