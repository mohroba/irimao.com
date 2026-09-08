<?php

namespace IMAOCustom\Services\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Morilog\Jalali\CalendarUtils;
use Morilog\Jalali\Jalalian;
use WP_Post;
use WP_Query;
use WP_Term;

class CompetitionCountdownWidget extends Widget_Base
{
    private const TAXONOMIES = [
        'gender'          => 'جنسیت',
        'board'           => 'استان',
        'competition_type'=> 'نوع مسابقه',
        'age_category'    => 'رده سنی',
        'level'           => 'سطح',
    ];

    public function get_name(): string
    {
        return 'imao_competition_countdown';
    }

    public function get_title(): string
    {
        return 'مسابقات همراه شمارش معکوس';
    }

    public function get_icon(): string
    {
        return 'eicon-countdown';
    }

    public function get_categories(): array
    {
        return ['general'];
    }

    public function get_keywords(): array
    {
        return ['competition', 'countdown', 'query', 'مسابقه', 'شمارش معکوس'];
    }

    public function get_style_depends(): array
    {
        return ['imao-competition-countdown'];
    }

    public function get_script_depends(): array
    {
        return ['imao-competition-countdown'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section('query_section', ['label' => 'پرس‌وجوی مسابقات']);

        $this->add_control('source', [
            'label'   => 'منبع',
            'type'    => Controls_Manager::SELECT,
            'default' => 'query',
            'options' => [
                'query'    => 'خودکار بر اساس فیلترها',
                'selected' => 'انتخاب دستی',
            ],
        ]);

        $this->add_control('competition_ids', [
            'label'       => 'مسابقات',
            'type'        => Controls_Manager::SELECT2,
            'multiple'    => true,
            'options'     => $this->get_competition_choices(),
            'label_block' => true,
            'condition'   => ['source' => 'selected'],
        ]);

        foreach (self::TAXONOMIES as $taxonomy => $label) {
            $this->add_control($taxonomy, [
                'label'       => $label,
                'type'        => Controls_Manager::SELECT2,
                'multiple'    => true,
                'options'     => $this->get_term_choices($taxonomy),
                'label_block' => true,
                'condition'   => ['source' => 'query'],
            ]);
        }

        $this->add_control('date_field', [
            'label'   => 'مبنای شمارش معکوس',
            'type'    => Controls_Manager::SELECT,
            'default' => 'start_date',
            'options' => [
                'start_date'       => 'تاریخ شروع مسابقه',
                'registration_end' => 'پایان ثبت‌نام',
                'end_date'         => 'تاریخ پایان مسابقه',
            ],
        ]);

        $this->add_control('date_status', [
            'label'   => 'وضعیت زمانی',
            'type'    => Controls_Manager::SELECT,
            'default' => 'future',
            'options' => [
                'future' => 'فقط آینده',
                'all'    => 'همه',
            ],
        ]);

        $this->add_control('posts_per_page', [
            'label'   => 'تعداد نمایش',
            'type'    => Controls_Manager::NUMBER,
            'default' => 12,
            'min'     => 1,
            'max'     => 100,
        ]);

        $this->add_control('order', [
            'label'   => 'ترتیب تاریخ',
            'type'    => Controls_Manager::SELECT,
            'default' => 'ASC',
            'options' => ['ASC' => 'نزدیک‌ترین ابتدا', 'DESC' => 'دورترین ابتدا'],
        ]);

        $this->add_control('query_id', [
            'label'       => 'شناسه پرس‌وجو',
            'type'        => Controls_Manager::TEXT,
            'description' => 'برای تغییر حرفه‌ای آرگومان‌ها با فیلتر imao/competition_countdown/query/{id}.',
        ]);

        $this->end_controls_section();

        $this->start_controls_section('content_section', ['label' => 'محتوا']);
        $this->add_control('link_target', [
            'label'   => 'لینک کارت',
            'type'    => Controls_Manager::SELECT,
            'default' => 'registration',
            'options' => [
                'registration' => 'صفحه جزئیات و ثبت‌نام',
                'permalink'    => 'صفحه مسابقه',
                'none'         => 'بدون لینک',
            ],
        ]);
        $this->add_control('expired_label', [
            'label'   => 'متن پایان شمارش',
            'type'    => Controls_Manager::TEXT,
            'default' => 'مهلت به پایان رسیده است',
        ]);
        $this->add_control('empty_message', [
            'label'   => 'پیام نبود مسابقه',
            'type'    => Controls_Manager::TEXT,
            'default' => 'مسابقه‌ای برای نمایش موجود نیست.',
        ]);
        $this->end_controls_section();
    }

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();
        $posts    = $this->get_competitions($settings);

        if (!$posts) {
            echo '<p class="imao-competition-countdown__empty">' . esc_html((string)($settings['empty_message'] ?? 'مسابقه‌ای برای نمایش موجود نیست.')) . '</p>';
            return;
        }

        $date_field    = $this->sanitize_date_field($settings['date_field'] ?? 'start_date');
        $expired_label = trim((string)($settings['expired_label'] ?? 'مهلت به پایان رسیده است'));

        echo '<div class="imao-competition-countdown" dir="rtl">';
        foreach ($posts as $post) {
            $timestamp = $this->jalali_timestamp((string)get_post_meta($post->ID, $date_field, true));
            $url       = $this->get_card_url($post, (string)($settings['link_target'] ?? 'registration'));
            $tag       = $url !== '' ? 'a' : 'article';
            $href      = $url !== '' ? ' href="' . esc_url($url) . '"' : '';

            echo '<' . $tag . ' class="imao-competition-countdown__card"' . $href . '>';
            echo '<h3 class="imao-competition-countdown__title">' . $this->format_title((string)$post->post_title) . '</h3>';

            if ($timestamp !== null && $timestamp > time()) {
                echo '<time class="imao-competition-countdown__timer" data-imao-countdown="' . esc_attr((string)$timestamp) . '" data-expired-label="' . esc_attr($expired_label) . '" datetime="' . esc_attr(gmdate('c', $timestamp)) . '" aria-live="off">';
                $this->render_time_part('days', 'روز');
                $this->render_time_part('hours', 'ساعت');
                $this->render_time_part('minutes', 'دقیقه');
                $this->render_time_part('seconds', 'ثانیه');
                echo '</time>';
            } else {
                echo '<span class="imao-competition-countdown__expired">' . esc_html($expired_label) . '</span>';
            }

            echo '</' . $tag . '>';
        }
        echo '</div>';
    }

    private function render_time_part(string $unit, string $label): void
    {
        echo '<span class="imao-competition-countdown__part"><span class="imao-competition-countdown__number" data-unit="' . esc_attr($unit) . '">۰۰</span><span class="imao-competition-countdown__label">' . esc_html($label) . '</span></span>';
    }

    /** @return array<int,WP_Post> */
    protected function get_competitions(array $settings): array
    {
        $date_field = $this->sanitize_date_field($settings['date_field'] ?? 'start_date');
        $limit      = min(100, max(1, (int)($settings['posts_per_page'] ?? 12)));
        $order      = strtoupper((string)($settings['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $args       = [
            'post_type'           => 'competition',
            'post_status'         => 'publish',
            'posts_per_page'      => $limit,
            'meta_key'            => $date_field,
            'orderby'             => 'meta_value',
            'order'               => $order,
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ];

        if (($settings['source'] ?? 'query') === 'selected') {
            $ids = $this->parse_ids($settings['competition_ids'] ?? []);
            if (!$ids) {
                return [];
            }
            $args['post__in'] = $ids;
        } else {
            $tax_query = ['relation' => 'AND'];
            foreach (array_keys(self::TAXONOMIES) as $taxonomy) {
                $term_ids = $this->parse_ids($settings[$taxonomy] ?? []);
                if ($term_ids) {
                    $tax_query[] = ['taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => $term_ids];
                }
            }
            if (count($tax_query) > 1) {
                $args['tax_query'] = $tax_query;
            }
        }

        if (($settings['date_status'] ?? 'future') === 'future') {
            $args['meta_query'] = [[
                'key'     => $date_field,
                'value'   => Jalalian::now($this->timezone())->format('Y/m/d'),
                'compare' => '>=',
                'type'    => 'CHAR',
            ]];
        }

        $args = apply_filters('imao/competition_countdown/query_args', $args, $settings, $this);
        $query_id = sanitize_key((string)($settings['query_id'] ?? ''));
        if ($query_id !== '') {
            $args = apply_filters('imao/competition_countdown/query/' . $query_id, $args, $settings, $this);
        }

        $query = new WP_Query($args);
        return is_array($query->posts) ? array_values(array_filter($query->posts, static fn($post): bool => $post instanceof WP_Post)) : [];
    }

    protected function get_competition_choices(): array
    {
        if (!function_exists('get_posts')) {
            return [];
        }
        $posts = get_posts(['post_type' => 'competition', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        $choices = [];
        foreach (is_array($posts) ? $posts : [] as $post) {
            if ($post instanceof WP_Post) {
                $choices[$post->ID] = sprintf('%s (#%d)', $post->post_title ?: 'مسابقه', $post->ID);
            }
        }
        return $choices;
    }

    protected function get_term_choices(string $taxonomy): array
    {
        if (!isset(self::TAXONOMIES[$taxonomy]) || !function_exists('get_terms')) {
            return [];
        }
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
        if (!is_array($terms) || (function_exists('is_wp_error') && is_wp_error($terms))) {
            return [];
        }
        $choices = [];
        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                $choices[$term->term_id] = $term->name;
            }
        }
        return $choices;
    }

    private function get_card_url(WP_Post $post, string $target): string
    {
        if ($target === 'none') {
            return '';
        }
        if ($target === 'permalink') {
            return (string)get_permalink($post->ID);
        }
        return (string)add_query_arg('competition_id', $post->ID, home_url('/my-account/competition-details/'));
    }

    private function format_title(string $title): string
    {
        $escaped = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return (string)preg_replace('/(&gt;&gt;.*?&lt;&lt;)/u', '<span style="color:#ff0000">$1</span>', $escaped);
    }

    private function jalali_timestamp(string $value): ?int
    {
        $value = trim(str_replace('-', '/', CalendarUtils::convertNumbers($value, true)));
        if ($value === '') {
            return null;
        }
        if (strpos($value, ' ') === false) {
            $value .= ' 00:00:00';
        }
        try {
            return Jalalian::fromFormat('Y/m/d H:i:s', $value, $this->timezone())->toCarbon()->getTimestamp();
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function timezone(): \DateTimeZone
    {
        $timezone = function_exists('wp_timezone_string') ? wp_timezone_string() : 'Asia/Tehran';
        try {
            return new \DateTimeZone($timezone ?: 'Asia/Tehran');
        } catch (\Exception $exception) {
            return new \DateTimeZone('Asia/Tehran');
        }
    }

    private function sanitize_date_field($field): string
    {
        $allowed = ['start_date', 'registration_end', 'end_date'];
        return in_array($field, $allowed, true) ? $field : 'start_date';
    }

    /** @return array<int> */
    private function parse_ids($value): array
    {
        $values = is_array($value) ? $value : explode(',', (string)$value);
        $ids = array_values(array_unique(array_filter(array_map(static function ($value): int {
            return abs((int)$value);
        }, $values))));
        sort($ids);
        return $ids;
    }
}
