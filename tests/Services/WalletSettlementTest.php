<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\Wallet;

class WalletOrderStub {
    public int $id;
    public int $customerId;
    public float $totalPaid;
    public float $total;
    /** @var array<string,mixed> */
    public array $meta = [];
    public string $status = 'pending';
    /** @var list<string> */
    public array $notes = [];
    public bool $saved = false;

    /**
     * @param array<string,mixed> $meta
     */
    public function __construct( int $id, int $customerId, float $totalPaid, float $total, array $meta = [] ) {
        $this->id         = $id;
        $this->customerId = $customerId;
        $this->totalPaid  = $totalPaid;
        $this->total      = $total;
        $this->meta       = $meta;
    }

    public function get_id(): int {
        return $this->id;
    }

    public function get_customer_id(): int {
        return $this->customerId;
    }

    public function get_total_paid(): float {
        return $this->totalPaid;
    }

    public function get_total(): float {
        return $this->total;
    }

    public function get_items(): array {
        return [];
    }

    public function get_meta( string $key ) {
        return $this->meta[ $key ] ?? '';
    }

    public function update_meta_data( string $key, $value ): void {
        $this->meta[ $key ] = $value;
    }

    public function delete_meta_data( string $key ): void {
        unset( $this->meta[ $key ] );
    }

    public function save(): void {
        $this->saved = true;
    }

    public function update_status( string $status, string $note = '' ): void {
        $this->status = $status;
        if ( $note !== '' ) {
            $this->notes[] = $note;
        }
    }

    public function add_order_note( string $note ): void {
        $this->notes[] = $note;
    }
}

class WalletSettlementTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['test_user_meta'] = [];
    }

    public function test_wallet_deduction_succeeds_with_sufficient_balance(): void {
        $wallet = new Wallet();
        $order  = new WalletOrderStub(
            10,
            5,
            50.0,
            50.0,
            [
                'crm_used_wallet'           => 100.0,
                'crm_wallet_original_total' => 150.0,
            ]
        );
        $GLOBALS['test_user_meta'] = [
            5 => [
                '_wallet_balance' => 200.0,
                'crm_wallet_log'  => [],
            ],
        ];

        $deducted = $this->invokeSettle( $wallet, $order, 5, 100.0 );

        $this->assertSame( 100.0, $deducted );
        $this->assertSame( 100.0, $order->meta['crm_used_wallet'] );
        $this->assertSame( 'completed', $order->meta['crm_wallet_processed'] );
        $this->assertTrue( $order->saved );
        $this->assertSame( 100.0, Wallet::get_balance( 5 ) );
        $this->assertSame( 'pending', $order->status );
        $this->assertArrayHasKey( 'crm_wallet_log', $GLOBALS['test_user_meta'][5] );
        $log = $GLOBALS['test_user_meta'][5]['crm_wallet_log'];
        $this->assertNotEmpty( $log );
        $this->assertSame( -100.0, $log[0]['amount'] );
    }

    public function test_payment_converts_to_wallet_topup_when_insufficient(): void {
        $wallet = new Wallet();
        $order  = new WalletOrderStub(
            11,
            7,
            50.0,
            50.0,
            [
                'crm_used_wallet'           => 100.0,
                'crm_wallet_original_total' => 150.0,
            ]
        );
        $GLOBALS['test_user_meta'] = [
            7 => [
                '_wallet_balance' => 20.0,
                'crm_wallet_log'  => [],
            ],
        ];

        $result = $this->invokeSettle( $wallet, $order, 7, 100.0 );

        $this->assertFalse( $result );
        $this->assertSame( 'failed', $order->meta['crm_wallet_processed'] );
        $this->assertSame( 'failed', $order->status );
        $this->assertSame( 70.0, Wallet::get_balance( 7 ) );
        $this->assertArrayHasKey( 'crm_wallet_log', $GLOBALS['test_user_meta'][7] );
        $log = $GLOBALS['test_user_meta'][7]['crm_wallet_log'];
        $this->assertNotEmpty( $log );
        $this->assertSame( 50.0, $log[0]['amount'] );
        $this->assertArrayNotHasKey( 'crm_used_wallet', $order->meta );
    }

    private function invokeSettle( Wallet $wallet, WalletOrderStub $order, int $userId, float $requested ) {
        $ref    = new \ReflectionClass( Wallet::class );
        $method = $ref->getMethod( 'settle_wallet_payment' );
        $method->setAccessible( true );
        return $method->invoke( $wallet, $order, $userId, $requested );
    }
}
