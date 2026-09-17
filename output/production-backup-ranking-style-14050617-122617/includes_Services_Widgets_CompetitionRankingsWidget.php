<?php

namespace IMAOCustom\Services\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use WP_Post;
use WP_Term;

class CompetitionRankingsWidget extends Widget_Base {
    public function get_name(): string {
        return 'imao_competition_rankings';
    }

    public function get_title(): string {
        return 'جدول رده‌بندی مسابقات';
    }

    public function get_icon(): string {
        return 'eicon-table';
    }

    /**
     * @return array<int,string>
     */
    public function get_categories(): array {
        return [ 'general' ];
    }

    /**
     * @return array<int,string>
     */
    public function get_keywords(): array {
        return [ 'ranking', 'competition', 'imao' ];
    }

    protected function register_controls(): void {
        $competition_options = $this->get_competition_choices();
        $weight_options      = $this->get_weight_class_choices();

        $this->start_controls_section(
            'content_section',
            [ 'label' => 'تنظیمات رده‌بندی' ]
        );

        $this->add_control(
            'all_competitions',
            [
                'label'        => 'همه مسابقات',
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => 'بله',
                'label_off'    => 'خیر',
                'return_value' => 'yes',
                'default'      => '',
                'description'  => 'با فعال‌سازی این گزینه، همه مسابقات فعلی و آینده به‌صورت خودکار نمایش داده می‌شوند.',
            ]
        );

        $this->add_control(
            'competition_id',
            [
                'label'       => 'مسابقات',
                'type'        => Controls_Manager::SELECT2,
                'multiple'    => true,
                'options'     => $competition_options,
                'label_block' => true,
                'description' => 'از مسابقات موجود انتخاب کنید. می‌توانید چند مسابقه را هم‌زمان انتخاب کنید.',
                'condition'   => [ 'all_competitions!' => 'yes' ],
            ]
        );

        $this->add_control(
            'weight_class',
            [
                'label'       => 'دستهٔ وزنی',
                'type'        => Controls_Manager::SELECT2,
                'multiple'    => true,
                'options'     => $weight_options,
                'label_block' => true,
                'description' => 'فهرست دسته‌های وزنی موجود. تنها دسته‌های مرتبط با مسابقات انتخاب‌شده در خروجی استفاده می‌شوند.',
            ]
        );

        $this->add_control(
            'gender',
            [
                'label'       => 'جنسیت',
                'type'        => Controls_Manager::SELECT2,
                'multiple'    => true,
                'label_block' => true,
                'options'     => [
                    'men'   => 'مردان',
                    'women' => 'زنان',
                ],
                'description' => 'انتخاب جنسیت‌های مجاز برای نمایش.',
            ]
        );

        $this->add_control(
            'order',
            [
                'label'   => 'ترتیب نمایش',
                'type'    => Controls_Manager::SELECT,
                'default' => 'points_desc',
                'options' => [
                    'points_desc' => 'بیشترین امتیاز ابتدا',
                    'points_asc'  => 'کمترین امتیاز ابتدا',
                    'name_asc'    => 'مرتب‌سازی بر اساس نام (صعودی)',
                    'name_desc'   => 'مرتب‌سازی بر اساس نام (نزولی)',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function render(): void {
        $settings     = $this->get_settings_for_display();
        $competitions = $this->resolve_competitions( $settings );
        $weight_ids   = $this->parse_id_list( $settings['weight_class'] ?? [] );
        $gender       = $this->sanitize_csv_value( $settings['gender'] ?? '' );
        $order        = $this->sanitize_csv_value( $settings['order'] ?? 'points_desc' );

        $valid_weights = $this->filter_weight_classes( $competitions, $weight_ids );

        $shortcode = sprintf(
            '[crm_competition_rankings competition="%s" weight="%s" gender="%s" order="%s"]',
            esc_attr( implode( ',', $competitions ) ),
            esc_attr( implode( ',', $valid_weights ) ),
            esc_attr( $gender ),
            esc_attr( $order )
        );

        echo do_shortcode( $shortcode );
    }

    /**
     * Normalize a comma-separated control value.
     */
    private function sanitize_csv_value( $value ): string {
        if ( is_array( $value ) ) {
            $value = implode( ',', array_filter( array_map( 'strval', $value ), 'strlen' ) );
        }

        $value = is_scalar( $value ) ? (string) $value : '';
        $value = trim( $value );

        if ( function_exists( 'sanitize_text_field' ) ) {
            return sanitize_text_field( $value );
        }

        return $value;
    }

    /**
     * @return array<int,string>
     */
    protected function get_competition_choices(): array {
        if ( ! function_exists( 'get_posts' ) ) {
            return [];
        }

        $posts = get_posts(
            [
                'post_type'      => 'competition',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'suppress_filters' => false,
            ]
        );

        if ( ! is_array( $posts ) ) {
            return [];
        }

        $choices = [];
        foreach ( $posts as $post ) {
            if ( ! $post instanceof WP_Post ) {
                continue;
            }
            $title          = trim( (string) $post->post_title );
            $normalized     = $title !== '' ? $title : sprintf( 'مسابقه #%d', (int) $post->ID );
            $choices[ $post->ID ] = sprintf( '%s (#%d)', $normalized, (int) $post->ID );
        }

        return $choices;
    }

    /** @return array<int> */
    protected function get_all_competition_ids(): array {
        if ( ! function_exists( 'get_posts' ) ) {
            return [];
        }
        $ids = get_posts( [
            'post_type' => 'competition', 'post_status' => 'publish', 'posts_per_page' => -1,
            'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => false,
        ] );
        return is_array( $ids ) ? $this->parse_id_list( $ids ) : [];
    }

    /** @param array<string,mixed> $settings @return array<int> */
    protected function resolve_competitions( array $settings ): array {
        return ( $settings['all_competitions'] ?? '' ) === 'yes'
            ? $this->get_all_competition_ids()
            : $this->parse_id_list( $settings['competition_id'] ?? [] );
    }

    /**
     * @param array<int> $competition_ids
     *
     * @return array<int,string>
     */
    protected function get_weight_class_choices( array $competition_ids = [] ): array {
        if ( ! function_exists( 'get_terms' ) ) {
            return [];
        }

        $args = [
            'taxonomy'   => 'age_category',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ];

        $competition_ids = array_values( array_filter( array_map( 'intval', $competition_ids ) ) );
        if ( $competition_ids ) {
            $args['object_ids'] = $competition_ids;
        }

        $terms = get_terms( $args );
        if ( ! is_array( $terms ) || ( function_exists( 'is_wp_error' ) && is_wp_error( $terms ) ) ) {
            return [];
        }

        $parents  = [];
        $children = [];
        foreach ( $terms as $term ) {
            if ( ! $term instanceof WP_Term ) {
                continue;
            }
            $term_id   = (int) $term->term_id;
            $parent_id = (int) $term->parent;
            if ( $term_id <= 0 ) {
                continue;
            }

            if ( $parent_id > 0 ) {
                $children[ $parent_id ][ $term_id ] = $term;
            } else {
                $parents[ $term_id ] = $term;
            }
        }

        $choices = [];
        foreach ( $children as $parent_id => $child_terms ) {
            $parent_name = isset( $parents[ $parent_id ] ) ? (string) $parents[ $parent_id ]->name : '';
            foreach ( $child_terms as $term_id => $term ) {
                $label            = trim( $parent_name !== '' ? $parent_name . ' - ' . $term->name : $term->name );
                $choices[ $term_id ] = $label;
            }
        }

        if ( ! $choices ) {
            foreach ( $parents as $term_id => $term ) {
                $choices[ $term_id ] = (string) $term->name;
            }
        }

        asort( $choices, SORT_FLAG_CASE | SORT_STRING );

        return $choices;
    }

    /**
     * @param mixed $value
     *
     * @return array<int>
     */
    private function parse_id_list( $value ): array {
        $items = is_array( $value ) ? $value : explode( ',', (string) $value );
        $ids   = [];

        foreach ( $items as $item ) {
            if ( is_string( $item ) ) {
                $item = trim( $item );
            }

            $id = is_numeric( $item ) ? (int) $item : 0;
            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }

        $ids = array_values( array_unique( $ids ) );
        sort( $ids );

        return $ids;
    }

    /**
     * Ensure selected weight classes are valid for the chosen competitions.
     *
     * @param array<int> $competitions
     * @param array<int> $weights
     *
     * @return array<int>
     */
    private function filter_weight_classes( array $competitions, array $weights ): array {
        if ( ! $competitions || ! $weights ) {
            return $weights;
        }

        $available = $this->get_weight_class_choices( $competitions );
        if ( ! $available ) {
            return [];
        }

        $allowed_ids = array_map( 'intval', array_keys( $available ) );
        $allowed_map = array_fill_keys( $allowed_ids, true );

        return array_values(
            array_filter(
                $weights,
                static fn( int $weight_id ): bool => isset( $allowed_map[ $weight_id ] )
            )
        );
    }
}

