<?php

namespace Djereg\Symfony\RabbitMQ\Transport;

use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * @author Samuel Roze <samuel.roze@gmail.com>
 *
 * @implements TransportFactoryInterface<AmqpTransport>
 */
class AmqpTransportFactory implements TransportFactoryInterface
{
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        unset($options['transport_name']);

        return new AmqpTransport(Connection::fromDsn($dsn, $options), $serializer);
    }

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'amqp://') || str_starts_with($dsn, 'amqps://');
    }
}
