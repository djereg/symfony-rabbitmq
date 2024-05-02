<?php

namespace Djereg\Symfony\RabbitMQ\MessageHandler;

use Datto\JsonRpc\Server;
use Djereg\Symfony\RabbitMQ\Event\MessageProcessedEvent;
use Djereg\Symfony\RabbitMQ\Event\MessageProcessingEvent;
use Djereg\Symfony\RabbitMQ\Message\RequestMessage;
use Djereg\Symfony\RabbitMQ\Message\ResponseMessage;
use Djereg\Symfony\RabbitMQ\Stamp\AmqpStamp;
use Djereg\Symfony\RabbitMQ\Stamp\HeaderStamp;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

readonly class RequestMessageHandler
{
    public function __construct(
        private Server $server,
        private MessageBusInterface $bus,
        private EventDispatcherInterface $dispatcher,
    ) {
        //
    }

    public function __invoke(RequestMessage $request, AMQPMessage $amqpMessage): void
    {
        $replyTo = $amqpMessage->get('reply_to');
        $correlationId = $amqpMessage->get('correlation_id');

        $headers = $request->getHeaders();

        $this->dispatcher->dispatch(new MessageProcessingEvent($request, $headers));
        $result = $this->server->reply($request->getBody());
        $this->dispatcher->dispatch(new MessageProcessedEvent($request, $headers));

        $this->bus->dispatch(
            new ResponseMessage($result),
            [
                new TransportNamesStamp(['rabbitmq']),
                new AmqpStamp('', $replyTo, [
                    'delivery_mode'  => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'correlation_id' => $correlationId,
                ]),
                new HeaderStamp([
                    'X-Message-Type' => 'response',
                ]),
            ]
        );
    }
}
