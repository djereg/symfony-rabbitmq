<?php

namespace Djereg\Symfony\RabbitMQ\MessageHandler;

use Djereg\Symfony\RabbitMQ\Event\MessageEvent;
use Djereg\Symfony\RabbitMQ\Event\MessageProcessedEvent;
use Djereg\Symfony\RabbitMQ\Event\MessageProcessingEvent;
use Djereg\Symfony\RabbitMQ\Message\EventReceiveMessage;
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
        $payload = $message->getPayload();
        $headers = $message->getHeaders();

        $this->dispatcher->dispatch(new MessageProcessingEvent($message, $headers));
        $this->dispatcher->dispatch(new MessageEvent($name, $payload, $headers), $name);
        $this->dispatcher->dispatch(new MessageProcessedEvent($message, $headers));
    }
}
