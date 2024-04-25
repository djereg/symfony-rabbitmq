<?php

namespace Djereg\Symfony\RabbitMQ\Core\EventListener;

use Djereg\Symfony\RabbitMQ\Core\Event\MessageSendingEvent;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

readonly class MessageSendingListener
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
    ) {
        //
    }

    public function __invoke(SendMessageToTransportsEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $this->dispatcher->dispatch(new MessageSendingEvent($envelope));
        $event->setEnvelope($envelope);
    }
}
