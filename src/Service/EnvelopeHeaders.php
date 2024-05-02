<?php

namespace Djereg\Symfony\RabbitMQ\Service;

use Djereg\Symfony\RabbitMQ\Stamp\AmqpReceivedStamp;
use Djereg\Symfony\RabbitMQ\Stamp\AmqpStamp;
use Djereg\Symfony\RabbitMQ\Stamp\HeaderStamp;
use PhpAmqpLib\Wire\AMQPTable;
use Symfony\Component\Messenger\Envelope;

class EnvelopeHeaders
{
    public function get(Envelope $envelope, string $name, mixed $default = null): mixed
    {
        return $this->all($envelope)[$name] ?? value($default);
    }

    public function all(Envelope $envelope): array
    {
        $headers = [];

        if ($amqpStamp = $envelope->last(AmqpStamp::class)) {
            $amqpHeaders = $amqpStamp->getAttributes()['application_headers'] ?? [];
            if ($amqpHeaders instanceof AMQPTable) {
                $headers[] = $amqpHeaders->getNativeData();
            } else {
                $headers[] = $amqpHeaders;
            }
        }

        if ($ampStamp = $envelope->last(AmqpReceivedStamp::class)) {
            /** @var AMQPTable $appHeaders */
            $appHeaders = $ampStamp->getAmqpMessage()->get('application_headers');
            $headers[] = $appHeaders->getNativeData();
        }

        /** @var HeaderStamp[] $headerStamps */
        $headerStamps = $envelope->all(HeaderStamp::class);
        foreach ($headerStamps as $stamp) {
            $headers[] = $stamp->getHeaders();
        }

        return array_merge(...$headers);
    }
}
