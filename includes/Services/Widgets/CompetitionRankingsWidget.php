<?php

namespace IMAOCustom\Services\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

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
        $this->start_controls_section(
            'content_section',
            [ 'label' => 'تنظیمات رده‌بندی' ]
        );

        $this->add_control(
            'competition_id',
            [
                'label'       => 'شناسه مسابقه',
                'type'        => Controls_Manager::TEXT,
                'label_block' => true,
                'description' => 'شناسه یا فهرست شناسه‌های مسابقه (با کاما جدا کنید).',
            ]
        );

        $this->add_control(
            'weight_class',
            [
                'label'       => 'دسته وزنی',
                'type'        => Controls_Manager::TEXT,
                'label_block' => true,
                'description' => 'شناسه یا فهرست دسته‌های وزنی (با کاما جدا کنید).',
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
        $competition  = $this->sanitize_csv_value( $settings['competition_id'] ?? '' );
        $weight_class = $this->sanitize_csv_value( $settings['weight_class'] ?? '' );
        $gender       = $this->sanitize_csv_value( $settings['gender'] ?? '' );
        $order        = $this->sanitize_csv_value( $settings['order'] ?? 'points_desc' );

        $shortcode = sprintf(
            '[crm_competition_rankings competition="%s" weight="%s" gender="%s" order="%s"]',
            esc_attr( $competition ),
            esc_attr( $weight_class ),
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
}

