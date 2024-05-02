<?php

namespace Djereg\Symfony\RabbitMQ\EventListener;

use Djereg\Symfony\RabbitMQ\Event\WorkerRunningEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;

class StopWorkerOnMessageLimitListener implements EventSubscriberInterface
{
    private int $maximumNumberOfMessages;
    private ?LoggerInterface $logger;
    private int $receivedMessages = 0;

    public function __construct(int $maximumNumberOfMessages, ?LoggerInterface $logger = null)
    {
        $this->maximumNumberOfMessages = $maximumNumberOfMessages;
        $this->logger = $logger;

        if ($maximumNumberOfMessages <= 0) {
            throw new InvalidArgumentException('Message limit must be greater than zero.');
        }
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (!$event->isWorkerIdle() && ++$this->receivedMessages >= $this->maximumNumberOfMessages) {
            $this->receivedMessages = 0;
            $event->getWorker()->stop();

            $this->logger?->info('Worker stopped due to maximum count of {count} messages processed', ['count' => $this->maximumNumberOfMessages]);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerRunningEvent::class => 'onWorkerRunning',
        ];
    }
}
