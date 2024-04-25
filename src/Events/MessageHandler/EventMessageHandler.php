<?php

namespace Djereg\Symfony\RabbitMQ\Events\MessageHandler;

use Djereg\Symfony\RabbitMQ\Events\Event\MessageEvent;
use Djereg\Symfony\RabbitMQ\Events\Message\EventReceiveMessage;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

readonly class EventMessageHandler
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
    ) {
        //
    }

    public function __invoke(EventReceiveMessage $message): void
    {
        $name = $message->getType();
        $event = new MessageEvent($name, $message->getPayload(), $message->getHeaders());
        $this->dispatcher->dispatch($event, $name);
    }
}
