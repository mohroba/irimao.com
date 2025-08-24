<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Courses;

class ProvinceTermsTest extends TestCase {
    public function test_province_terms_are_unique(): void {
        $terms = Courses::province_terms();
        $this->assertCount(31, $terms);
        $names = array_values($terms);
        $slugs = array_keys($terms);
        $this->assertSame($names, array_unique($names));
        $this->assertSame($slugs, array_unique($slugs));
    }
}
