<?php

namespace Elementor {
    if (!class_exists('\\Elementor\\Widget_Base')) {
        class Widget_Base
        {
            public function __construct($data = [], $args = null) {}
            protected function get_settings_for_display(): array { return []; }
            protected function start_controls_section(...$args): void {}
            protected function end_controls_section(): void {}
            protected function add_control(...$args): void {}
        }
    }

    if (!class_exists('\\Elementor\\Controls_Manager')) {
        class Controls_Manager
        {
            public const TEXT = 'text';
            public const SELECT2 = 'select2';
            public const SELECT = 'select';
            public const NUMBER = 'number';
        }
    }
}

namespace {
    use IMAOCustom\Services\Widgets\CompetitionCountdownWidget;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    require_once __DIR__ . '/../../includes/Services/Widgets/CompetitionCountdownWidget.php';

    class CompetitionCountdownWidgetTest extends TestCase
    {
        private function widget(): CompetitionCountdownWidget
        {
            return new class() extends CompetitionCountdownWidget {
                public function __construct() {}
            };
        }

        public function test_identity_and_assets_are_registered(): void
        {
            $widget = $this->widget();

            $this->assertSame('imao_competition_countdown', $widget->get_name());
            $this->assertSame(['imao-competition-countdown'], $widget->get_style_depends());
            $this->assertSame(['imao-competition-countdown'], $widget->get_script_depends());
        }

        public function test_id_parser_rejects_invalid_and_duplicate_values(): void
        {
            $method = new ReflectionMethod(CompetitionCountdownWidget::class, 'parse_ids');
            $method->setAccessible(true);

            $this->assertSame([5, 7, 9], $method->invoke($this->widget(), ['7', 5, 'bad', 7, '9']));
        }

        public function test_date_field_is_allowlisted(): void
        {
            $method = new ReflectionMethod(CompetitionCountdownWidget::class, 'sanitize_date_field');
            $method->setAccessible(true);

            $this->assertSame('registration_end', $method->invoke($this->widget(), 'registration_end'));
            $this->assertSame('start_date', $method->invoke($this->widget(), 'unexpected_meta_key'));
        }

        public function test_gender_marker_is_highlighted_without_allowing_html(): void
        {
            $method = new ReflectionMethod(CompetitionCountdownWidget::class, 'format_title');
            $method->setAccessible(true);
            $title = $method->invoke($this->widget(), '<script>alert(1)</script> >> بانوان <<');

            $this->assertStringNotContainsString('<script>', $title);
            $this->assertStringContainsString('<span style="color:#ff0000">&gt;&gt; بانوان &lt;&lt;</span>', $title);
        }
    }
}
