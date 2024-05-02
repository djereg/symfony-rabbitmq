<?php

namespace Djereg\Symfony\RabbitMQ\EventListener;

use Djereg\Symfony\RabbitMQ\Stamp\AmqpReceivedStamp;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;

class AddHandlerArgumentListener
{
    public function __invoke(WorkerMessageReceivedEvent $event): void
    {
        $stamp = $event->getEnvelope()->last(AmqpReceivedStamp::class);

        $event->addStamps(
            new HandlerArgumentsStamp([$stamp->getAmqpMessage()]),
        );
    }
}
