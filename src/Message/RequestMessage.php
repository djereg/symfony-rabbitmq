<?php

namespace Djereg\Symfony\RabbitMQ\Message;

readonly class RequestMessage
{
    public function __construct(
        private string $body,
        private array $headers = [],
    ) {
        //
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
