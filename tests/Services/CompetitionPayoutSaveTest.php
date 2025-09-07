<?php
namespace IMAOCustom\Services {
    if (!function_exists(__NAMESPACE__.'\\update_post_meta')) { function update_post_meta($id,$key,$val){ $GLOBALS['updated_meta'][$key]=$val; } }
    if (!function_exists(__NAMESPACE__.'\\sanitize_text_field')) { function sanitize_text_field($v){ return is_string($v)?trim($v):$v; } }
}
namespace {
    use PHPUnit\Framework\TestCase;
    use IMAOCustom\Services\Competitions;
    if (!class_exists('WP_Post')) { class WP_Post { public $post_type; } }
    class CompetitionPayoutSaveTest extends TestCase {
        protected function setUp(): void { $GLOBALS['updated_meta']=[]; }
        public function test_user_mode_saves_recipient_type(): void {
            $_POST = [
                'crm_payouts_nonce'=>'n',
                'payout_mode'=>'user',
                'payout_user_id'=>[7],
                'payout_user_type'=>['fixed'],
                'payout_user_value'=>['30'],
            ];
            $svc=new Competitions(); $post=new WP_Post(); $post->post_type='competition';
            $svc->save_meta(1,$post,true);
            $this->assertSame('user',$GLOBALS['updated_meta']['_competition_payout_mode']);
            $rows=$GLOBALS['updated_meta']['_competition_payouts'];
            $this->assertSame('user',$rows[0]['recipient_type']);
            $this->assertSame(7,$rows[0]['user_id']);
            $this->assertArrayNotHasKey('role',$rows[0]);
        }
        public function test_predefined_mode_saves_recipient_type(): void {
            $_POST = [
                'crm_payouts_nonce'=>'n',
                'payout_mode'=>'predefined',
                'payout_role'=>['documentation'],
                'payout_type'=>['fixed'],
                'payout_value'=>['15'],
            ];
            $svc=new Competitions(); $post=new WP_Post(); $post->post_type='competition';
            $svc->save_meta(1,$post,true);
            $this->assertSame('predefined',$GLOBALS['updated_meta']['_competition_payout_mode']);
            $rows=$GLOBALS['updated_meta']['_competition_payouts'];
            $this->assertSame('predefined',$rows[0]['recipient_type']);
            $this->assertSame('documentation',$rows[0]['role']);
        }
    }
}
