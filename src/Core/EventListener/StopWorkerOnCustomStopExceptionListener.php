<?php

namespace Djereg\Symfony\RabbitMQ\Core\EventListener;

use Djereg\Symfony\RabbitMQ\Core\Event\WorkerRunningEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\StopWorkerExceptionInterface;

class StopWorkerOnCustomStopExceptionListener implements EventSubscriberInterface
{
    private bool $stop = false;

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $th = $event->getThrowable();
        if ($th instanceof StopWorkerExceptionInterface) {
            $this->stop = true;
        }
        if ($th instanceof HandlerFailedException) {
            foreach ($th->getWrappedExceptions() as $e) {
                if ($e instanceof StopWorkerExceptionInterface) {
                    $this->stop = true;
                    break;
                }
            }
        }
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if ($this->stop) {
            $event->getWorker()->stop();
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'onMessageFailed',
            WorkerRunningEvent::class => 'onWorkerRunning',
        ];
    }
}
