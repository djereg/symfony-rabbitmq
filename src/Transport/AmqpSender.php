<?php

namespace Djereg\Symfony\RabbitMQ\Transport;

use Djereg\Symfony\RabbitMQ\Stamp\AmqpReceivedStamp;
use Djereg\Symfony\RabbitMQ\Stamp\AmqpStamp;
use Djereg\Symfony\RabbitMQ\Stamp\ChannelStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AmqpSender implements SenderInterface
{
    private SerializerInterface $serializer;
    private Connection $connection;

    public function __construct(Connection $connection, ?SerializerInterface $serializer = null)
    {
        $this->connection = $connection;
        $this->serializer = $serializer ?? new PhpSerializer();
    }

    public function send(Envelope $envelope): Envelope
    {
        $amqpStamp = $envelope->last(AmqpStamp::class) ?? new AmqpStamp('');

        $encodedMessage = $this->serializer->encode($envelope);

        /** @var DelayStamp|null $delayStamp */
        $delayStamp = $envelope->last(DelayStamp::class);
        $delay = $delayStamp?->getDelay() ?? 0;

        if (isset($encodedMessage['headers']['Content-Type'])) {
            $contentType = $encodedMessage['headers']['Content-Type'];
            if (!$amqpStamp || !isset($amqpStamp->getAttributes()['content_type'])) {
                $amqpStamp = AmqpStamp::createWithAttributes(['content_type' => $contentType], $amqpStamp);
            }
        }

        $amqpReceivedStamp = $envelope->last(AmqpReceivedStamp::class);
        if ($amqpReceivedStamp instanceof AmqpReceivedStamp) {
            $redeliveryStamp = $envelope->last(RedeliveryStamp::class);
            $retryRoutingKey = $redeliveryStamp ? $amqpReceivedStamp->getQueueName() : null;
            $amqpStamp = AmqpStamp::createFromAmqpMessage(
                $amqpReceivedStamp->getAmqpMessage(),
                $amqpStamp,
                $retryRoutingKey,
            );
        }

        $channelStamp = $envelope->last(ChannelStamp::class);
        $channel = $channelStamp?->getChannel();

        $this->connection->publish(
            $encodedMessage['body'],
            $encodedMessage['headers'] ?? [],
            $delay,
            $amqpStamp,
            $channel
        );

        return $envelope;
    }
}
