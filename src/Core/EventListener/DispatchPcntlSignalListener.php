<?php

namespace Djereg\Symfony\RabbitMQ\Core\EventListener;

use Djereg\Symfony\RabbitMQ\Core\Event\WorkerRunningEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class DispatchPcntlSignalListener implements EventSubscriberInterface
{
    public function onWorkerRunning(): void
    {
        pcntl_signal_dispatch();
    }

    public static function getSubscribedEvents(): array
    {
        if (!\function_exists('pcntl_signal_dispatch')) {
            return [];
        }

        return [
            WorkerRunningEvent::class => ['onWorkerRunning', 100],
        ];
    }
}
