<?php

namespace Djereg\Symfony\RabbitMQ\Core\Transport;

use Djereg\Symfony\RabbitMQ\Core\Stamp\AmqpReceivedStamp;
use Djereg\Symfony\RabbitMQ\Core\Stamp\AmqpStamp;
use Djereg\Symfony\RabbitMQ\Core\Stamp\ChannelStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\QueueReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class AmqpTransport implements QueueReceiverInterface, TransportInterface, SetupableTransportInterface, MessageCountAwareInterface
{
    private SerializerInterface $serializer;
    private Connection $connection;
    private AmqpReceiver $receiver;
    private AmqpSender $sender;

    public function __construct(Connection $connection, ?SerializerInterface $serializer = null)
    {
        $this->connection = $connection;
        $this->serializer = new Serializer();
    }

    public function get(): iterable
    {
        return $this->getReceiver()->get();
    }

    public function getFromQueues(array $queueNames): iterable
    {
        return $this->getReceiver()->getFromQueues($queueNames);
    }

    public function setup(): void
    {
        $this->connection->setup();
    }

    public function getMessageCount(): int
    {
        return $this->getReceiver()->getMessageCount();
    }

    public function ack(Envelope $envelope): void
    {
        $this->getReceiver()->ack($envelope);
    }

    public function reject(Envelope $envelope): void
    {
        $this->getReceiver()->reject($envelope);
    }

    public function send(Envelope $envelope): Envelope
    {
        return $this->getSender()->send($envelope);
    }

    public function bind(array $events): void
    {
        $this->connection->bind($events);
    }

    public function consumer(callable $handler): void
    {
        $this->getReceiver()->consumer($handler);
    }

    public function consume(): void
    {
        $this->getReceiver()->consume();
    }

    public function stopConsume(): void
    {
        $this->getReceiver()->stopConsume();
    }

    public function request(Envelope $envelope): Envelope
    {
        $channel = $this->connection->createChannel();
        $ampqStamp = $envelope->last(AmqpStamp::class);
        $attributes = $ampqStamp->getAttributes();

        $replyTo = $attributes['reply_to'] ?? null;
        $correlationId = $attributes['correlation_id'] ?? null;

        $response = null;

        $handler = function (Envelope $envelope) use ($channel, $correlationId, &$response) {

            // Get the message from the stamp
            $amqpMessage = $envelope->last(AmqpReceivedStamp::class)->getAmqpMessage();

            // Check if the correlation id matches
            if ($correlationId !== $amqpMessage->get('correlation_id')) {
                return;
            }

            $response = $envelope;
            $channel->stopConsume();
            $channel->close();
        };

        $this->getReceiver()->consumer($handler, $replyTo, $channel, true);

        $this->getSender()->send(
            $envelope->with($ampqStamp, new ChannelStamp($channel))
        );

        $this->getReceiver()->consume($channel);

        return $response;
    }

    private function getReceiver(): AmqpReceiver
    {
        return $this->receiver ??= new AmqpReceiver($this->connection, $this->serializer);
    }

    private function getSender(): AmqpSender
    {
        return $this->sender ??= new AmqpSender($this->connection, $this->serializer);
    }
}
