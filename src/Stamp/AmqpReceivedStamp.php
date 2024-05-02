<?php

namespace Djereg\Symfony\RabbitMQ\Stamp;

use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

readonly class AmqpReceivedStamp implements NonSendableStampInterface
{
    public function __construct(
        private AMQPMessage $amqpMessage,
        private string $queueName,
    ) {
        //
    }

    public function getAmqpMessage(): AMQPMessage
    {
        return $this->amqpMessage;
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }
}
