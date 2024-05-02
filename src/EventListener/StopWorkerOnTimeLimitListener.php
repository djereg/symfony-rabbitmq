<?php

namespace Djereg\Symfony\RabbitMQ\EventListener;

use Djereg\Symfony\RabbitMQ\Event\WorkerRunningEvent;
use Djereg\Symfony\RabbitMQ\Event\WorkerStartedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;

class StopWorkerOnTimeLimitListener implements EventSubscriberInterface
{
    private float $endTime = 0;

    public function __construct(
        private readonly int $timeLimitInSeconds,
        private readonly ?LoggerInterface $logger = null,
    ) {
        if ($timeLimitInSeconds <= 0) {
            throw new InvalidArgumentException('Time limit must be greater than zero.');
        }
    }

    public function onWorkerStarted(): void
    {
        $startTime = microtime(true);
        $this->endTime = $startTime + $this->timeLimitInSeconds;
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if ($this->endTime < microtime(true)) {
            $event->getWorker()->stop();
            $this->logger?->info('Worker stopped due to time limit of {timeLimit}s exceeded', ['timeLimit' => $this->timeLimitInSeconds]);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onWorkerStarted',
            WorkerRunningEvent::class => 'onWorkerRunning',
        ];
    }
}
