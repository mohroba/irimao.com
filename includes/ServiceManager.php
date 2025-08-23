<?php

namespace IMAOCustom;

use Throwable;

class ServiceManager
{
    /**
     * @var array<int,object>
     */
    private array $services = [];

    /**
     * @var array<int,string>
     */
    private array $service_classes;

    /**
     * @param array<int,string> $service_classes
     */
    public function __construct(array $service_classes)
    {
        $this->service_classes = $service_classes;
    }

    /**
     * Instantiate and register all services.
     *
     * @return array<int,object> Successfully registered services
     */
    public function register_all(): array
    {
        foreach ($this->service_classes as $class) {
            try {
                $service = new $class();
                if (method_exists($service, 'register')) {
                    $service->register();
                }
                $this->services[] = $service;
            } catch (Throwable $e) {
                Logger::error(
                    sprintf('Service %s failed: %s', $class, $e->getMessage()),
                    ['exception' => $e]
                );
            }
        }

        return $this->services;
    }

    /**
     * @return array<int,object>
     */
    public function get_services(): array
    {
        return $this->services;
    }
}
