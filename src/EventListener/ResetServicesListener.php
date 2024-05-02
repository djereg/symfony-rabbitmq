<?php

namespace Djereg\Symfony\RabbitMQ\EventListener;

use Djereg\Symfony\RabbitMQ\Event\WorkerRunningEvent;
use Djereg\Symfony\RabbitMQ\Event\WorkerStoppedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetter;

class ResetServicesListener implements EventSubscriberInterface
{
    private ServicesResetter $servicesResetter;

    public function __construct(ServicesResetter $servicesResetter)
    {
        $this->servicesResetter = $servicesResetter;
    }

    public function resetServices(WorkerRunningEvent $event): void
    {
        if (!$event->isWorkerIdle()) {
            $this->servicesResetter->reset();
        }
    }

    public function resetServicesAtStop(WorkerStoppedEvent $event): void
    {
        $this->servicesResetter->reset();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerRunningEvent::class => ['resetServices', -1024],
            WorkerStoppedEvent::class => ['resetServicesAtStop', -1024],
        ];
    }
}
