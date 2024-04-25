<?php

namespace Djereg\Symfony\RabbitMQ\Core\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

readonly class HeaderStamp implements StampInterface
{
    public function __construct(
        private array $headers,
    ) {
        //
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
