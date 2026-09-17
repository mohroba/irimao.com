<?php

namespace {
    if ( ! function_exists( 'shortcode_atts' ) ) {
        function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
            return array_merge( $pairs, (array) $atts );
        }
    }
}

namespace IMAOCustom\Services {
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;

    class CompetitionCardTemplatesTest extends TestCase {
        protected function tearDown(): void {
            $this->set_context( [] );
        }

        public function test_qr_shortcode_returns_the_generated_image_by_default(): void {
            $this->set_context( [ [ 'qr_data_uri' => 'data:image/svg+xml;base64,PHN2Zz4=' ] ] );

            $html = ( new CompetitionCardTemplates() )->qr_shortcode();

            $this->assertSame(
                '<img src="data:image/svg+xml;base64,PHN2Zz4=" alt="QR استعلام اصالت کارت" class="imao-card-qr">',
                $html
            );
        }

        public function test_qr_shortcode_supports_builder_css_classes_and_alt_text(): void {
            $this->set_context( [ [ 'qr_data_uri' => 'data:image/svg+xml;base64,PHN2Zz4=' ] ] );

            $html = ( new CompetitionCardTemplates() )->qr_shortcode( [
                'class' => 'builder-qr',
                'alt'   => 'Card verification',
            ] );

            $this->assertStringContainsString( 'class="builder-qr"', $html );
            $this->assertStringContainsString( 'alt="Card verification"', $html );
        }

        /** @param array<int,array<string,string>> $context */
        private function set_context( array $context ): void {
            $property = new ReflectionProperty( CompetitionCardTemplates::class, 'context_stack' );
            $property->setAccessible( true );
            $property->setValue( null, $context );
        }
    }
}
