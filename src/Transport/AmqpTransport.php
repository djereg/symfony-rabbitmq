<?php

namespace Djereg\Symfony\RabbitMQ\Transport;

use PhpAmqpLib\Channel\AMQPChannel;
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

    public function heartbeat(): void
    {
        $this->connection->checkHeartBeat();
    }

    public function channel(bool $new = false): AMQPChannel
    {
        if ($new) {
            return $this->connection->createChannel();
        }
        return $this->connection->getChannel();
    }

    public function bind(array $events): void
    {
        $this->connection->bind($events);
    }

    public function consumer(callable $handler, string $queue = null, AMQPChannel $channel = null, bool $noAck = false): string
    {
        return $this->getReceiver()->consumer($handler, $queue, $channel, $noAck);
    }

    public function consume(): void
    {
        $this->getReceiver()->consume();
    }

    public function stopConsume(): void
    {
        $this->getReceiver()->stopConsume();
    }

    public function getWaitTimeout(float $maximumPoll = 10.0): float
    {
        return $this->connection->getWaitTimeout($maximumPoll);
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
