<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Helpers\AgeCategory;

class AgeCategoryTest extends TestCase
{
    /** @runInSeparateProcess */
    public function test_slug_from_age_uses_meta(): void
    {
        require_once __DIR__ . '/../Services/stubs.php';
        $this->assertSame('adults-18-38', AgeCategory::slug_from_age(20));
        $this->assertSame('', AgeCategory::slug_from_age(40));
    }
}
