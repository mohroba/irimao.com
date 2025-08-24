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
    public function xlsx(array $users): string { return $this->build_xlsx($users); }
}

class CompetitionAttendeeExportTest extends TestCase {
    public function test_xlsx_contains_meta_values(): void {
        if (!class_exists(Competitions::class)) { $this->markTestSkipped('Plugin not loaded.'); }
        $u = new WP_User();
        $u->ID = 1; $u->display_name = 'User1'; $u->user_email = 'u1@example.com';
        $xlsx = (new CompetitionsExportStub())->xlsx([$u]);
        $tmp = tmpfile();
        fwrite($tmp, $xlsx);
        fflush($tmp);
        $meta = stream_get_meta_data($tmp);
        $sheet = IOFactory::load($meta['uri'])->getActiveSheet();
        $this->assertSame('billing_phone_1', $sheet->getCell('D2')->getValue());
        $this->assertSame('national_id_1', $sheet->getCell('E2')->getValue());
        $this->assertSame('User101', $sheet->getCell('X2')->getValue());
        $this->assertSame('User1', $sheet->getCell('B2')->getValue());
        fclose($tmp);
    }
}
