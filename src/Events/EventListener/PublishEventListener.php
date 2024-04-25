<?php

namespace Djereg\Symfony\RabbitMQ\Events\EventListener;

use Djereg\Symfony\RabbitMQ\Core\Stamp\AmqpStamp;
use Djereg\Symfony\RabbitMQ\Core\Stamp\HeaderStamp;
use Djereg\Symfony\RabbitMQ\Events\Event\MessagePublishEvent;
use Djereg\Symfony\RabbitMQ\Events\Message\EventPublishMessage;
use Illuminate\Support\Str;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

readonly class PublishEventListener
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
        //
    }

    public function __invoke(MessagePublishEvent $event): void
    {
        $eventName = $event->event();
        $message = new EventPublishMessage($eventName, $event->payload());

        $this->bus->dispatch($message, [
            new TransportNamesStamp(['rabbitmq']),
            new AmqpStamp(null, $eventName, [
                'delivery_mode'  => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'correlation_id' => (string)Str::uuid(),
            ]),
            new HeaderStamp([
                'X-Message-Type' => 'event',
                'X-Event-Name'   => $eventName,
            ]),
        ]);
    }
}
