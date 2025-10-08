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
use IMAOCustom\Services\Competitions;
use PhpOffice\PhpSpreadsheet\IOFactory;

if (!class_exists('WP_User')) { class WP_User { public $ID; public $display_name; public $user_email; } }

class CompetitionsExportStub extends Competitions {
    public function xlsx(array $users, int $competition_id): string { return $this->build_xlsx($users, $competition_id); }
    protected function get_registration_meta_map(int $competition_id, array $users): array {
        $map = [];
        foreach ($users as $user) {
            if (!($user instanceof WP_User)) {
                continue;
            }
            $map[$user->ID] = ['weight_class' => 'Heavy', 'age_category' => 'Junior'];
        }
        return $map;
    }
}

class CompetitionAttendeeExportTest extends TestCase {
    public function test_xlsx_contains_meta_values(): void {
        if (!class_exists(Competitions::class)) { $this->markTestSkipped('Plugin not loaded.'); }
        update_post_meta(20, '_linked_product_id', 200);
        $u = new WP_User();
        $u->ID = 1; $u->display_name = 'User1'; $u->user_email = 'u1@example.com';
        $GLOBALS['test_user_meta'][$u->ID]['billing_phone'] = 'billing_phone_1';
        $GLOBALS['test_user_meta'][$u->ID]['national_id'] = 'national_id_1';
        $GLOBALS['test_user_meta'][$u->ID]['coach_id'] = 101;
        $GLOBALS['test_user_meta'][$u->ID]['club_id'] = 102;
        $GLOBALS['test_user_meta'][102]['club_name'] = 'ClubName102';
        $xlsx = (new CompetitionsExportStub())->xlsx([$u], 20);
        $tmp = tmpfile();
        fwrite($tmp, $xlsx);
        fflush($tmp);
        $meta = stream_get_meta_data($tmp);
        $sheet = IOFactory::load($meta['uri'])->getActiveSheet();
        $this->assertSame('Heavy', $sheet->getCell('Z2')->getValue());
        $this->assertSame('Junior', $sheet->getCell('AA2')->getValue());
        $this->assertSame('User1', $sheet->getCell('B2')->getValue());
        fclose($tmp);
    }
}
