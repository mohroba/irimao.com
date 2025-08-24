<?php
namespace IMAOCustom\Helpers {
    class Bootstrap {
        public static $enqueued = false;
        public static function enqueue(): void { self::$enqueued = true; }
    }
}

namespace {
    use PHPUnit\Framework\TestCase;
    use IMAOCustom\Services\Endpoints\EditBasicInfo;
    use IMAOCustom\Helpers\Bootstrap;

    if (!function_exists('is_wc_endpoint_url')) {
        function is_wc_endpoint_url(string $endpoint): bool {
            return $endpoint === 'edit-basic-info';
        }
    }

    class EditBasicInfoTest extends TestCase {
        public function test_enqueue_assets_loads_bootstrap(): void {
            $ep = new EditBasicInfo();
            $ep->enqueue_assets();
            $this->assertTrue(Bootstrap::$enqueued);
        }
    }
}
