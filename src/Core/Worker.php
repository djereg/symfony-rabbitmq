<?php

namespace Djereg\Symfony\RabbitMQ\Core;

use Djereg\Symfony\RabbitMQ\Core\Event\MessageReceivedEvent;
use Djereg\Symfony\RabbitMQ\Core\Transport\AmqpTransport;
use Djereg\Symfony\RabbitMQ\Core\Event\WorkerRunningEvent;
use Djereg\Symfony\RabbitMQ\Core\Event\WorkerStartedEvent;
use Djereg\Symfony\RabbitMQ\Core\Event\WorkerStoppedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use SplObjectStorage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRateLimitedEvent;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RejectRedeliveredMessageException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\AckStamp;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\FlushBatchHandlersStamp;
use Symfony\Component\Messenger\Stamp\NoAutoAckStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\WorkerMetadata;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class Worker
{
    private WorkerMetadata $metadata;
    private array $acks = [];
    private SplObjectStorage $unacks;

    private string $transportName = 'rabbitmq';

    public function __construct(
        private readonly AmqpTransport $receiver,
        private readonly MessageBusInterface $bus,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?RateLimiterFactory $rateLimiter = null,
        private readonly ?array $listenedEvents = null,
    ) {
        $this->metadata = new WorkerMetadata([
            'transportNames' => [$this->transportName],
        ]);
        $this->unacks = new SplObjectStorage();
    }

    /**
     * Receive the messages and dispatch them to the bus.
     *
     * Valid options are:
     *  * sleep (default: 1000000): Time in microseconds to sleep after no messages are found
     *  * queues: The queue names to consume from, instead of consuming from all queues. When this is used, all receivers must implement the QueueReceiverInterface
     */
    public function run(): void
    {
        $this->eventDispatcher?->dispatch(new WorkerStartedEvent($this));

        // Initialize the queue, exchange and routing keys
        $this->receiver->setup();

        if ($this->listenedEvents) {
            $this->receiver->bind($this->listenedEvents);
        }

        $this->receiver->consumer(function (Envelope $envelope) {

            // The worker is still running, and it's not idle, it's working hard!
            $this->eventDispatcher?->dispatch(new WorkerRunningEvent($this, false));

            // IF there are too many messages were handled, rest a bit!
            $this->rateLimit($this->transportName);

            // Handle the message
            $this->handleMessage($envelope, $this->transportName);

            // The worker is still running, and it's idle, time to rest a bit!
            $this->eventDispatcher?->dispatch(new WorkerRunningEvent($this, true));
        });
        $this->receiver->consume();

        $this->flush(true);
        $this->eventDispatcher?->dispatch(new WorkerStoppedEvent($this));
    }

    private function handleMessage(Envelope $envelope, string $transportName): void
    {
        $event = new WorkerMessageReceivedEvent($envelope, $transportName);
        $this->eventDispatcher?->dispatch($event);
        $envelope = $event->getEnvelope();

        if (!$event->shouldHandle()) {
            return;
        }

        $event = new MessageReceivedEvent($envelope);
        $this->eventDispatcher?->dispatch($event);
        $envelope = $event->getEnvelope();

        $acked = false;
        $ack = function (Envelope $envelope, ?\Throwable $e = null) use ($transportName, &$acked) {
            $acked = true;
            $this->acks[] = [$transportName, $envelope, $e];
        };

        try {
            $e = null;
            $envelope = $this->bus->dispatch(
                $envelope->with(
                    new ReceivedStamp($transportName),
                    new ConsumedByWorkerStamp(),
                    new AckStamp($ack)
                )
            );
        } catch (\Throwable $e) {
        }

        /** @var NoAutoAckStamp|null $noAutoAckStamp */
        $noAutoAckStamp = $envelope->last(NoAutoAckStamp::class);

        if (!$acked && !$noAutoAckStamp) {
            $this->acks[] = [$transportName, $envelope, $e];
        } elseif ($noAutoAckStamp) {
            $this->unacks[$noAutoAckStamp->getHandlerDescriptor()->getBatchHandler()] = [$envelope->withoutAll(AckStamp::class), $transportName];
        }

        $this->ack();
    }

    private function ack(): bool
    {
        $acks = $this->acks;
        $this->acks = [];

        foreach ($acks as [$transportName, $envelope, $e]) {

            if (null !== $e) {
                if ($rejectFirst = $e instanceof RejectRedeliveredMessageException) {
                    // redelivered messages are rejected first so that continuous failures in an event listener or while
                    // publishing for retry does not cause infinite redelivery loops
                    $this->receiver->reject($envelope);
                }

                if ($e instanceof HandlerFailedException || ($e instanceof DelayedMessageHandlingException && null !== $e->getEnvelope())) {
                    $envelope = $e->getEnvelope();
                }

                $failedEvent = new WorkerMessageFailedEvent($envelope, $transportName, $e);

                $this->eventDispatcher?->dispatch($failedEvent);
                $envelope = $failedEvent->getEnvelope();

                if (!$rejectFirst) {
                    $this->receiver->reject($envelope);
                }

                continue;
            }

            $handledEvent = new WorkerMessageHandledEvent($envelope, $transportName);
            $this->eventDispatcher?->dispatch($handledEvent);
            $envelope = $handledEvent->getEnvelope();

            if (null !== $this->logger) {
                $message = $envelope->getMessage();
                $context = [
                    'class'      => $message::class,
                    'message_id' => $envelope->last(TransportMessageIdStamp::class)?->getId(),
                ];
                $this->logger->info('{class} was handled successfully (acknowledging to transport).', $context);
            }

            $this->receiver->ack($envelope);
        }

        return (bool)$acks;
    }

    private function rateLimit(string $transportName): void
    {
        if (!$this->rateLimiter) {
            return;
        }

        $rateLimiter = $this->rateLimiter->create();
        if ($rateLimiter->consume()->isAccepted()) {
            return;
        }

        $this->logger?->info('Transport {transport} is being rate limited, waiting for token to become available...', ['transport' => $transportName]);

        $this->eventDispatcher?->dispatch(new WorkerRateLimitedEvent($rateLimiter, $transportName));
        $rateLimiter->reserve()->wait();
    }

    private function flush(bool $force): bool
    {
        $unacks = $this->unacks;

        if (!$unacks->count()) {
            return false;
        }

        $this->unacks = new SplObjectStorage();

        foreach ($unacks as $batchHandler) {
            [$envelope, $transportName] = $unacks[$batchHandler];
            try {
                $this->bus->dispatch($envelope->with(new FlushBatchHandlersStamp($force)));
                $envelope = $envelope->withoutAll(NoAutoAckStamp::class);
                unset($unacks[$batchHandler], $batchHandler);
            } catch (\Throwable $e) {
                $this->acks[] = [$transportName, $envelope, $e];
            }
        }

        return $this->ack();
    }

    public function stop(): void
    {
        $this->logger?->info('Stopping worker.', ['transport_names' => $this->metadata->getTransportNames()]);
        $this->receiver->stopConsume();
    }

    public function getMetadata(): WorkerMetadata
    {
        return $this->metadata;
    }
}
