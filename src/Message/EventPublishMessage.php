<?php

namespace Djereg\Symfony\RabbitMQ\Message;

readonly class EventPublishMessage
{
    public function __construct(
        private string $type,
        private array $payload,
        private array $headers = [],
    ) {
        //
    }

    public function getType(): string
    {
        return $this->type;
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
