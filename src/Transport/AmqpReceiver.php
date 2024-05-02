<?php

namespace Djereg\Symfony\RabbitMQ\Transport;

use Djereg\Symfony\RabbitMQ\Stamp\AmqpReceivedStamp;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\QueueReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AmqpReceiver implements QueueReceiverInterface, MessageCountAwareInterface
{
    private SerializerInterface $serializer;
    private Connection $connection;

    public function __construct(Connection $connection, ?SerializerInterface $serializer = null)
    {
        $this->connection = $connection;
        $this->serializer = $serializer ?? new PhpSerializer();
    }

    public function get(): iterable
    {
        throw new RuntimeException('This method cannot be used with the AMQP transport.');
    }

    public function getFromQueues(array $queueNames): iterable
    {
        throw new RuntimeException('This method cannot be used with the AMQP transport.');
    }

    public function consumer(callable $callback, string $queue = null, AMQPChannel $channel = null, bool $noAck = false, string $consumerTag = ''): string
    {
        $queue ??= $this->connection->getQueue();
        $channel ??= $this->connection->getChannel();

        $handler = function (AMQPMessage $message) use ($callback, $queue) {

            $headers = $message->get('application_headers');
            if ($headers instanceof AMQPTable) {
                $headers = $headers->getNativeData();
            }

            $envelope = $this->serializer->decode([
                'body'    => $message->getBody(),
                'headers' => $headers,
            ]);

            $callback($envelope->with(new AmqpReceivedStamp($message, $queue)));
        };

        return $channel->basic_consume(
            queue: $queue,
            consumer_tag: $consumerTag,
            no_ack: $noAck,
            callback: $handler,
        );
    }

    public function consume(AMQPChannel $channel = null): void
    {
        $channel ??= $this->connection->getChannel();
        $channel->consume();
    }

    public function stopConsume(AMQPChannel $channel = null): void
    {
        $channel ??= $this->connection->getChannel();
        $channel->stopConsume();
    }

    public function ack(Envelope $envelope): void
    {
        $stamp = $this->findAmqpStamp($envelope);
        $this->connection->ack($stamp->getAmqpMessage());
    }

    public function reject(Envelope $envelope): void
    {
        $stamp = $this->findAmqpStamp($envelope);
        $this->rejectAmqpEnvelope($stamp->getAmqpMessage());
    }

    public function getMessageCount(): int
    {
        return $this->connection->countMessagesInQueues();
    }

    private function rejectAmqpEnvelope(AMQPMessage $amqpMessage): void
    {
        $this->connection->nack($amqpMessage);
    }

    private function findAmqpStamp(Envelope $envelope): AmqpReceivedStamp
    {
        $amqpReceivedStamp = $envelope->last(AmqpReceivedStamp::class);
        if (null === $amqpReceivedStamp) {
            throw new LogicException('No "AmqpReceivedStamp" stamp found on the Envelope.');
        }

        return $amqpReceivedStamp;
    }
}
