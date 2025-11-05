<?php

namespace IMAOCustom\Services;

class OrderStatus {
    private const META_LINKED_POST = '_linked_post_id';

    /**
     * Post types that should trigger automatic completion when purchased.
     *
     * @var string[]
     */
    private array $target_post_types = [ 'course', 'competition' ];

    /**
     * Register WordPress hooks.
     */
    public function register(): void {
        \add_action( 'woocommerce_payment_complete', [ $this, 'mark_order_paid' ], 20 );
    }

    /**
     * Ensure orders containing only course or competition items are marked as paid.
     */
    public function mark_order_paid( int $order_id ): void {
        if ( ! \function_exists( 'wc_get_order' ) || ! \function_exists( 'get_post_meta' ) || ! \function_exists( 'get_post_type' ) ) {
            return;
        }

        $order = \wc_get_order( $order_id );
        if ( ! \is_object( $order ) || ! \method_exists( $order, 'get_items' ) ) {
            return;
        }

        if ( ! $this->should_mark_as_paid( $order ) ) {
            return;
        }

        if ( \method_exists( $order, 'has_status' ) && $order->has_status( [ 'completed', 'cancelled', 'refunded', 'failed' ] ) ) {
            return;
        }

        if ( \method_exists( $order, 'update_status' ) ) {
            $order->update_status( 'completed', $this->get_completion_note() );
        }
    }

    /**
     * Determine whether the order only contains relevant products.
     *
     * @param object $order Order instance from WooCommerce.
     */
    private function should_mark_as_paid( $order ): bool {
        $items = $order->get_items();
        if ( ! \is_iterable( $items ) ) {
            return false;
        }

        $has_relevant_item = false;
        foreach ( $items as $item ) {
            if ( ! \is_object( $item ) || ! \method_exists( $item, 'get_product_id' ) ) {
                return false;
            }

            $product_id = (int) $item->get_product_id();
            if ( $product_id <= 0 ) {
                return false;
            }

            $linked_id = (int) \get_post_meta( $product_id, self::META_LINKED_POST, true );
            if ( $linked_id <= 0 ) {
                return false;
            }

            $post_type = \get_post_type( $linked_id );
            if ( ! \in_array( $post_type, $this->target_post_types, true ) ) {
                return false;
            }

            $has_relevant_item = true;
        }

        return $has_relevant_item;
    }

    private function get_completion_note(): string {
        $message = 'Order auto-completed after successful course or competition payment.';
        return \function_exists( '__' ) ? \__( $message, 'imao-custom-plugin' ) : $message;
    }
}
