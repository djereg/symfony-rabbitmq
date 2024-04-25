<?php

namespace Djereg\Symfony\RabbitMQ\Core\Event;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\StampInterface;

class MessageSendingEvent
{
    public function __construct(
        private Envelope $envelope,
    ) {
        //
    }

    public function getEnvelope(): Envelope
    {
        return $this->envelope;
    }

    public function addStamps(StampInterface ...$stamps): void
    {
        $this->envelope = $this->envelope->with(...$stamps);
    }
}
