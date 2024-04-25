<?php

namespace Djereg\Symfony\RabbitMQ\Events\Message;

use Djereg\Symfony\RabbitMQ\Events\Event\MessageEvent;

abstract readonly class EventMessage
{
    public function __construct(
        private MessageEvent $event,
    ) {
        //
    }

    public function getMessageEvent(): MessageEvent
    {
        return $this->event;
    }

    public function getEvent(): string
    {
        return $this->event->getEvent();
    }

    public function getPayload(): array
    {
        return $this->event->getPayload();
    }

    public function getHeaders(): array
    {
        return $this->event->getHeaders();
    }
}
