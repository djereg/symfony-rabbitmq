<?php

namespace Djereg\Symfony\RabbitMQ\Message;

readonly class ResponseMessage
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
