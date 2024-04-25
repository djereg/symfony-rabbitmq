<?php

namespace Djereg\Symfony\RabbitMQ\Events\EventListener;

use Djereg\Symfony\RabbitMQ\Events\Event\MessageEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

abstract class MessageEventListener
{
    protected string $message;

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly ?LoggerInterface $logger = null,
    ) {
        //
    }

    public function __invoke(MessageEvent $event): void
    {
        try {
            $this->bus->dispatch(new $this->message($event));
        } catch (Throwable $e) {
            $this->logger?->error($e);
        }
    }
}
