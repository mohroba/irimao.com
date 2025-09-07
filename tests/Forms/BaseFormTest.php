<?php
namespace {
    if (!function_exists('selected')) { function selected($selected, $current, $echo = true){ return $selected == $current ? ' selected="selected"' : ''; } }
    if (!function_exists('esc_attr')) { function esc_attr($v){ return $v; } }
    if (!function_exists('esc_html')) { function esc_html($v){ return $v; } }
    if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v){ return $v; } }
}

namespace IMAOCustom\Forms {
    class BaseFormStub extends BaseForm {
        protected function fields(): array { return []; }
        protected function submit(): void {}
        public function expose_read_date(string $base): string { return $this->read_date($base); }
        public function expose_date_select(string $name, string $value = '', bool $required = false): string { return $this->date_select($name, $value, $required); }
    }
}

namespace {
    use PHPUnit\Framework\TestCase;
    use IMAOCustom\Forms\BaseFormStub;

    class BaseFormTest extends TestCase {
        protected function setUp(): void {
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }

        public function test_read_date_formats_parts(): void {
            $_POST = ['event_year' => '1401', 'event_month' => '3', 'event_day' => '4'];
            $form = new BaseFormStub();
            $this->assertSame('1401/03/04', $form->expose_read_date('event'));
        }

        public function test_date_select_marks_selected_options(): void {
            $form = new BaseFormStub();
            $html = $form->expose_date_select('birth', '1400/02/05', true);
            $this->assertStringContainsString('name="birth_year"', $html);
            $this->assertStringContainsString('value="1400" selected', $html);
            $this->assertStringContainsString('value="2" selected', $html);
            $this->assertStringContainsString('value="5" selected', $html);
        }

        public function test_date_select_handles_varied_input_formats(): void {
            $form = new BaseFormStub();
            $html = $form->expose_date_select('birth', '۱۴۰۰-۰۲-۰۵ 00:00:00', true);
            $this->assertStringContainsString('value="1400" selected', $html);
            $this->assertStringContainsString('value="2" selected', $html);
            $this->assertStringContainsString('value="5" selected', $html);
        }
    }
}
