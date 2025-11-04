<?php

use IMAOCustom\Services\OrderStatus;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase {
    protected $backupGlobals = false;
    /** @var array<int,array<string,mixed>> */
    private array $originalTestPostMeta = [];

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['actions']      = [];
        $GLOBALS['test_orders']  = [];
        $GLOBALS['post_meta']    = [];
        $GLOBALS['post_types']   = [];
        $this->originalTestPostMeta = $GLOBALS['test_post_meta'] ?? [];

        if ( ! function_exists( 'add_action' ) ) {
            function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
                $GLOBALS['actions'][ $hook ][] = [ $callback, $priority, $accepted_args ];
            }
        }

        if ( ! function_exists( 'wc_get_order' ) ) {
            function wc_get_order( $order_id ) {
                return $GLOBALS['test_orders'][ $order_id ] ?? null;
            }
        }

        if ( ! function_exists( 'get_post_meta' ) ) {
            function get_post_meta( $post_id, $key, $single = true ) {
                return $GLOBALS['post_meta'][ $post_id ][ $key ] ?? '';
            }
        }

        if ( ! function_exists( 'get_post_type' ) ) {
            function get_post_type( $post_id ) {
                return $GLOBALS['post_types'][ $post_id ] ?? '';
            }
        }

        if ( ! function_exists( '__' ) ) {
            function __( $text, $domain = null ) {
                return $text;
            }
        }
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['actions'],
            $GLOBALS['test_orders'],
            $GLOBALS['post_meta'],
            $GLOBALS['post_types']
        );
        $GLOBALS['test_post_meta'] = $this->originalTestPostMeta;

        parent::tearDown();
    }

    public function test_register_hooks_payment_complete(): void {
        $service = new OrderStatus();
        $service->register();

        $this->assertArrayHasKey( 'woocommerce_payment_complete', $GLOBALS['actions'] );
    }

    public function test_order_marked_paid_for_course_and_competition_items(): void {
        $order = new TestOrder( [ new TestOrderItem( 101 ), new TestOrderItem( 102 ) ] );
        $GLOBALS['test_orders'][1] = $order;

        $GLOBALS['test_post_meta'][101]['_linked_post_id'] = 10;
        $GLOBALS['test_post_meta'][102]['_linked_post_id'] = 20;

        $this->assertSame( 10, (int) get_post_meta( 101, '_linked_post_id', true ) );
        $this->assertSame( 'course', get_post_type( 10 ) );

        $service = new OrderStatus();
        $ref     = new \ReflectionClass( OrderStatus::class );
        $method  = $ref->getMethod( 'should_mark_as_paid' );
        $method->setAccessible( true );
        $this->assertTrue( $method->invoke( $service, $order ) );
        $service->mark_order_paid( 1 );

        $this->assertSame( 'completed', $order->get_status() );
        $this->assertCount( 1, $order->status_log );
    }

    public function test_order_not_marked_paid_when_irrelevant_items_present(): void {
        $order = new TestOrder( [ new TestOrderItem( 301 ), new TestOrderItem( 302 ) ] );
        $GLOBALS['test_orders'][2] = $order;

        $GLOBALS['test_post_meta'][301]['_linked_post_id'] = 10;
        $GLOBALS['test_post_meta'][302]['_linked_post_id'] = 0;

        $service = new OrderStatus();
        $service->mark_order_paid( 2 );

        $this->assertSame( 'processing', $order->get_status() );
        $this->assertCount( 0, $order->status_log );
    }
}

class TestOrder {
    private array $items;
    private string $status;
    public array $status_log = [];

    public function __construct( array $items, string $status = 'processing' ) {
        $this->items  = $items;
        $this->status = $status;
    }

    public function get_items(): array {
        return $this->items;
    }

    public function has_status( $statuses ): bool {
        $statuses = (array) $statuses;
        return in_array( $this->status, $statuses, true );
    }

    public function update_status( string $status, string $note = '' ): void {
        $this->status        = $status;
        $this->status_log[] = [ 'status' => $status, 'note' => $note ];
    }

    public function get_status(): string {
        return $this->status;
    }
}

class TestOrderItem {
    private int $product_id;

    public function __construct( int $product_id ) {
        $this->product_id = $product_id;
    }

    public function get_product_id(): int {
        return $this->product_id;
    }
}
