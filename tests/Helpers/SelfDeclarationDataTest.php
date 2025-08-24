<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Helpers\SelfDeclarationData;

class SelfDeclarationDataTest extends TestCase {
    public function test_degree_label_returns_persian_label_when_defined(): void {
        $label = SelfDeclarationData::degree_label(2, '2');
        $this->assertSame('درجه ۳', $label);
    }

    public function test_degree_label_returns_value_when_not_found(): void {
        $label = SelfDeclarationData::degree_label(999, 'foo');
        $this->assertSame('foo', $label);
    }

    public function test_degree_label_returns_dash_when_empty(): void {
        $label = SelfDeclarationData::degree_label(1, '');
        $this->assertSame('—', $label);
    }
}
