<?php

namespace Djereg\Symfony\RabbitMQ\Core\EventListener;

use Djereg\Symfony\RabbitMQ\Core\Event\WorkerRunningEvent;
use Djereg\Symfony\RabbitMQ\Core\Event\WorkerStoppedEvent;
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
