<?php

namespace IMAOCustom\Tests;

use IMAOCustom\ServiceManager;
use PHPUnit\Framework\TestCase;

class ServiceManagerTest extends TestCase
{
    public function test_register_all_handles_exceptions(): void
    {
        $manager  = new ServiceManager([GoodService::class, FailingService::class]);
        $services = $manager->register_all();

        $this->assertCount(1, $services);
        $this->assertInstanceOf(GoodService::class, $services[0]);
    }
}

class GoodService
{
    public function register(): void {}
}

class FailingService
{
    public function register(): void
    {
        throw new \RuntimeException('failed');
    }
}
