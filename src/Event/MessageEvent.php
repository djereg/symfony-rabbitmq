<?php

namespace Djereg\Symfony\RabbitMQ\Event;

readonly class MessageEvent
{
    public function __construct(
        private string $event,
        private array $payload,
        private array $headers,
    ) {
        //
    }

    public function getEvent(): string
    {
        return $this->event;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
