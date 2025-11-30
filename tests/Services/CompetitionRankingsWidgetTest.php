<?php

namespace Elementor {
    if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
        class Widget_Base {
            public function __construct( $data = [], $args = null ) {}
            protected function get_settings_for_display(): array { return []; }
            protected function start_controls_section( ...$args ): void {}
            protected function end_controls_section(): void {}
            protected function add_control( ...$args ): void {}
        }
    }

    if ( ! class_exists( '\\Elementor\\Controls_Manager' ) ) {
        class Controls_Manager {
            public const TEXT   = 'text';
            public const SELECT2 = 'select2';
            public const SELECT = 'select';
        }
    }
}

namespace {
    use IMAOCustom\Services\Widgets\CompetitionRankingsWidget;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    if ( ! class_exists( '\\WP_Post' ) ) {
        class WP_Post {
            public $ID;
            public $post_title;

            public function __construct( $id = 0, $title = '' ) {
                $this->ID         = $id;
                $this->post_title = $title;
            }
        }
    }

    if ( ! class_exists( '\\WP_Term' ) ) {
        class WP_Term {
            public $term_id;
            public $parent;
            public $name;

            public function __construct( $id = 0, $name = '', $parent = 0 ) {
                $this->term_id = $id;
                $this->name    = $name;
                $this->parent  = $parent;
            }
        }
    }

    require_once __DIR__ . '/../../includes/Services/Widgets/CompetitionRankingsWidget.php';

    class CompetitionRankingsWidgetTest extends TestCase {
        private function widget(): CompetitionRankingsWidget {
            return new class() extends CompetitionRankingsWidget {
                public array $weight_choices = [];

                public function __construct() {}

                protected function get_weight_class_choices( array $competition_ids = [] ): array {
                    return $this->weight_choices;
                }

                protected function get_competition_choices(): array {
                    return [];
                }

                protected function get_settings_for_display(): array {
                    return [];
                }
            };
        }

        public function test_parse_id_list_sanitizes_values(): void {
            $widget  = $this->widget();
            $method  = new ReflectionMethod( CompetitionRankingsWidget::class, 'parse_id_list' );
            $method->setAccessible( true );

            $result = $method->invoke( $widget, [ '5', ' 7', 'abc', 7, '9 ' ] );

            $this->assertSame( [ 5, 7, 9 ], $result );
        }

        public function test_filter_weight_classes_keeps_valid_ids(): void {
            $widget                   = $this->widget();
            $widget->weight_choices   = [ 2 => 'A', 3 => 'B' ];
            $filter_method            = new ReflectionMethod( CompetitionRankingsWidget::class, 'filter_weight_classes' );
            $filter_method->setAccessible( true );

            $weights = $filter_method->invoke( $widget, [ 10 ], [ 1, 2, 3 ] );

            $this->assertSame( [ 2, 3 ], $weights );
        }
    }
}
