<?php

namespace Djereg\Symfony\RabbitMQ\EventListener;

use Djereg\Symfony\RabbitMQ\Event\MessagePublishingEvent;
use Djereg\Symfony\RabbitMQ\Transport\AmqpTransport;
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
        if (!$this->isRabbitMQ($event)) {
            return;
        }
        $envelope = $event->getEnvelope();
        $result = $this->dispatcher->dispatch(
            new MessagePublishingEvent($envelope)
        );
        $event->setEnvelope($result->getEnvelope());
    }

    private function isRabbitMQ(SendMessageToTransportsEvent $event): bool
    {
        foreach ($event->getSenders() as $sender) {
            if ($sender instanceof AmqpTransport) {
                return true;
            }
        }
        return false;
    }
}
