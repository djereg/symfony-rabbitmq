<?php

namespace Djereg\Symfony\RabbitMQ\RPC\MessageHandler;

use Datto\JsonRpc\Server;
use Djereg\Symfony\RabbitMQ\Core\Stamp\AmqpStamp;
use Djereg\Symfony\RabbitMQ\Core\Stamp\HeaderStamp;
use Djereg\Symfony\RabbitMQ\RPC\Message\RequestMessage;
use Djereg\Symfony\RabbitMQ\RPC\Message\ResponseMessage;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

readonly class RequestMessageHandler
{
    public function __construct(
        private Server $server,
        private MessageBusInterface $bus,
    ) {
        //
    }

    public function __invoke(RequestMessage $request, AMQPMessage $amqpMessage): void
    {
        $replyTo = $amqpMessage->get('reply_to');
        $correlationId = $amqpMessage->get('correlation_id');

        $result = $this->server->reply($request->getBody());
        $response = new ResponseMessage($result);

        $this->bus->dispatch($response, [
            new TransportNamesStamp(['rabbitmq']),
            new AmqpStamp('', $replyTo, [
                'delivery_mode'  => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'correlation_id' => $correlationId,
            ]),
            new HeaderStamp([
                'X-Message-Type' => 'response',
            ]),
        ]);
    }
}
