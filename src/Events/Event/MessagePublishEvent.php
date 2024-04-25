<?php

namespace Djereg\Symfony\RabbitMQ\Events\Event;

use Symfony\Contracts\EventDispatcher\Event;

abstract class MessagePublishEvent extends Event
{
    protected string $event;
    protected string $queue;

    public function event(): ?string
    {
        return $this->event ?? null;
    }

    public function queue(): ?string
    {
        return $this->queue ?? null;
    }

    public function payload(): array
    {
        return [];
    }
}
