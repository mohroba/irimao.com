<?php

use IMAOCustom\Services\MonthlyTuition;
use PHPUnit\Framework\TestCase;

class MonthlyCheckoutRouteProbe extends MonthlyTuition {
    public int $calls = 0;
    public function start_checkout(): void { ++$this->calls; }
}

final class MonthlyCheckoutRouteTest extends TestCase {
    public function test_only_explicit_payment_posts_dispatch(): void {
        $previous_get = $_GET;
        $previous_post = $_POST;
        $previous_method = $_SERVER['REQUEST_METHOD'] ?? null;
        try {
            $service = new MonthlyCheckoutRouteProbe();
            $_GET = [ 'imao_class_action' => 'pay' ];
            $_POST = [ 'action' => 'imao_pay_monthly_class' ];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $service->handle_frontend_checkout();
            $this->assertSame( 0, $service->calls );
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['action'] = 'unrelated';
            $service->handle_frontend_checkout();
            $this->assertSame( 0, $service->calls );
            $_POST['action'] = 'imao_pay_monthly_class';
            $_GET['imao_class_action'] = 'submit';
            $service->handle_frontend_checkout();
            $this->assertSame( 0, $service->calls );
            $_GET['imao_class_action'] = 'pay';
            $service->handle_frontend_checkout();
            $this->assertSame( 1, $service->calls );
        } finally {
            $_GET = $previous_get;
            $_POST = $previous_post;
            if ( $previous_method === null ) unset( $_SERVER['REQUEST_METHOD'] );
            else $_SERVER['REQUEST_METHOD'] = $previous_method;
        }
    }
}
