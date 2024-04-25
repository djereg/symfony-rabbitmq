<?php

namespace Djereg\Symfony\RabbitMQ\Core\EventListener;

use Djereg\Symfony\RabbitMQ\Core\Event\WorkerRunningEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;

class StopWorkerOnFailureLimitListener implements EventSubscriberInterface
{
    private int $maximumNumberOfFailures;
    private ?LoggerInterface $logger;
    private int $failedMessages = 0;

    public function __construct(int $maximumNumberOfFailures, ?LoggerInterface $logger = null)
    {
        $this->maximumNumberOfFailures = $maximumNumberOfFailures;
        $this->logger = $logger;

        if ($maximumNumberOfFailures <= 0) {
            throw new InvalidArgumentException('Failure limit must be greater than zero.');
        }
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        ++$this->failedMessages;
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (!$event->isWorkerIdle() && $this->failedMessages >= $this->maximumNumberOfFailures) {
            $this->failedMessages = 0;
            $event->getWorker()->stop();

            $this->logger?->info('Worker stopped due to limit of {count} failed message(s) is reached', ['count' => $this->maximumNumberOfFailures]);
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
