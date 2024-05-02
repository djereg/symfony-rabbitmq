<?php

namespace Djereg\Symfony\RabbitMQ\Event;

readonly class MessageProcessingEvent
{
    public function __construct(
        private object $message,
        private array $headers,
    ) {
        //
    }

    public function getMessage(): object
    {
        return $this->message;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
