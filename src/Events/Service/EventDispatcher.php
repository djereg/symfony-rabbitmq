<?php

namespace Djereg\Symfony\RabbitMQ\Events\Service;

use Djereg\Symfony\RabbitMQ\Events\Event\MessagePublishEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

readonly class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private EventDispatcherInterface $dispatcher
    ) {
        //
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        if ($event instanceof MessagePublishEvent) {
            $eventName = MessagePublishEvent::class;
        }
        return $this->dispatcher->dispatch($event, $eventName);
    }
}
