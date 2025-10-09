<?php
if (!function_exists('get_user_meta')) {
    function get_user_meta($id, $key, $single = true) {
        if ($key === 'club_name') { return 'ClubName'.$id; }
        if ($key === 'coach_id' || $key === 'club_id') { return 100 + $id; }
        return $key.'_'.$id;
    }
}
if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) {
        $u = new WP_User();
        $u->ID = $value;
        $u->display_name = 'User'.$value;
        $u->user_email = 'u'.$value.'@example.com';
        return $u;
    }
}
if (!function_exists('esc_html')) { function esc_html($v){ return $v; } }

use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Courses;
use PhpOffice\PhpSpreadsheet\IOFactory;

if (!class_exists('WP_User')) { class WP_User { public $ID; public $display_name; public $user_email; } }

class CoursesExportStub extends Courses {
    private const META_LINKED_PRODUCT = '_linked_product_id';
    public function xlsx(array $users, int $course_id): string { return $this->build_xlsx($users, $course_id); }
    public function registrationMetaMap(int $course_id, array $users): array { return $this->get_registration_meta_map($course_id, $users); }
    protected function get_registration_meta_map(int $course_id, array $users): array {
        if (!function_exists('wc_get_orders')) {
            return [];
        }
        $product_id = (int) get_post_meta($course_id, self::META_LINKED_PRODUCT, true);
        if (!$product_id) {
            return [];
        }
        $meta_map = [];
        foreach (wc_get_orders(['limit' => -1, 'status' => ['processing', 'completed']]) as $order) {
            if (!is_object($order) || !method_exists($order, 'get_user_id') || !method_exists($order, 'get_items')) {
                continue;
            }
            $uid = (int) $order->get_user_id();
            if (!$uid) {
                continue;
            }
            foreach ($order->get_items() as $item) {
                if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                    continue;
                }
                if ((int) $item->get_product_id() !== $product_id) {
                    continue;
                }
                $weight = trim((string) $item->get_meta('دسته وزنی', true));
                $age    = trim((string) $item->get_meta('رده سنی', true));
                if (!isset($meta_map[$uid])) {
                    $meta_map[$uid] = ['weight_class' => $weight, 'age_category' => $age];
                    continue;
                }
                if ($weight !== '' && ($meta_map[$uid]['weight_class'] ?? '') === '') {
                    $meta_map[$uid]['weight_class'] = $weight;
                }
                if ($age !== '' && ($meta_map[$uid]['age_category'] ?? '') === '') {
                    $meta_map[$uid]['age_category'] = $age;
                }
            }
        }
        return $meta_map;
    }
}

class CourseAttendeeExportTest extends TestCase {
    public function test_xlsx_contains_meta_values(): void {
        if (!class_exists(Courses::class)) { $this->markTestSkipped('Plugin not loaded.'); }
        update_post_meta(10, '_linked_product_id', 100);
        $u = new WP_User();
        $u->ID = 1; $u->display_name = 'User1'; $u->user_email = 'u1@example.com';
        $GLOBALS['test_user_meta'][$u->ID]['billing_phone'] = 'billing_phone_1';
        $GLOBALS['test_user_meta'][$u->ID]['national_id'] = 'national_id_1';
        $GLOBALS['test_user_meta'][$u->ID]['coach_id'] = 101;
        $GLOBALS['test_user_meta'][$u->ID]['club_id'] = 102;
        $GLOBALS['test_user_meta'][102]['club_name'] = 'ClubName102';
        $svc = new CoursesExportStub();
        $meta_map = $svc->registrationMetaMap(10, [$u]);
        $this->assertSame(['weight_class' => 'Light', 'age_category' => 'Senior'], $meta_map[$u->ID] ?? []);
        $xlsx = $svc->xlsx([$u], 10);
        $tmp = tmpfile();
        fwrite($tmp, $xlsx);
        fflush($tmp);
        $meta = stream_get_meta_data($tmp);
        $sheet = IOFactory::load($meta['uri'])->getActiveSheet();
        $this->assertSame('User1', $sheet->getCell('B2')->getValue());
        $this->assertSame('Light', $sheet->getCell('Z2')->getValue());
        $this->assertSame('Senior', $sheet->getCell('AA2')->getValue());
        fclose($tmp);
    }
}
