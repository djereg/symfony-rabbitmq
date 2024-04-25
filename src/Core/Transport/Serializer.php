<?php

namespace Djereg\Symfony\RabbitMQ\Core\Transport;

use Djereg\Symfony\RabbitMQ\Core\Stamp\AmqpStamp;
use Djereg\Symfony\RabbitMQ\Core\Stamp\HeaderStamp;
use Djereg\Symfony\RabbitMQ\Events\Message\EventPublishMessage;
use Djereg\Symfony\RabbitMQ\Events\Message\EventReceiveMessage;
use Djereg\Symfony\RabbitMQ\RPC\Message\RequestMessage;
use Djereg\Symfony\RabbitMQ\RPC\Message\ResponseMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

readonly class Serializer implements SerializerInterface
{
    private PhpSerializer $phpSerializer;

    public function __construct()
    {
        $this->phpSerializer = new PhpSerializer();
    }

    public function encode(Envelope $envelope): array
    {
        if ($envelope->last(RedeliveryStamp::class)) {
            return $this->encodeMessage($envelope);
        }

        $message = $envelope->getMessage();
        $headers = $this->getHeaders($envelope);

        if ($message instanceof EventPublishMessage) {
            return $this->encodeEvent($message, $headers);
        }
        if ($message instanceof RequestMessage) {
            return $this->encodeRequest($message, $headers);
        }
        if ($message instanceof ResponseMessage) {
            return $this->encodeResponse($message, $headers);
        }

        return $this->encodeMessage($envelope);
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        // Get the message type from the headers
        $type = $encodedEnvelope['headers']['X-Message-Type'] ?? null;

        if (!$type) {
            throw new RuntimeException('Message type not found in headers');
        }

        switch ($type) {
            case 'message':
                return $this->decodeMessage($encodedEnvelope);
            case 'event':
                return $this->decodeEvent($encodedEnvelope);
            case 'request':
                return $this->decodeRequest($encodedEnvelope);
            case 'response':
                return $this->decodeResponse($encodedEnvelope);
            default:
                throw new RuntimeException('Unsupported message type: ' . $type);
        }
    }

    private function encodeMessage(Envelope $envelope): array
    {
        $encoded = $this->phpSerializer->encode($envelope);

        return array_merge($encoded, [
            'headers' => [
                'X-Message-Type' => 'message',
                'Content-Type'   => 'application/php-serialized',
            ],
        ]);
    }

    private function encodeEvent(EventPublishMessage $message, array $headers): array
    {
        return [
            'body'    => json_encode($message->getPayload()),
            'headers' => array_merge($message->getHeaders(), $headers, [
                'Content-Type' => 'application/json',
            ]),
        ];
    }

    private function encodeRequest(RequestMessage $message, array $headers): array
    {
        return [
            'body'    => $message->getBody(),
            'headers' => array_merge($message->getHeaders(), $headers, [
                'Content-Type' => 'application/json',
            ]),
        ];
    }

    private function encodeResponse(ResponseMessage $message, array $headers): array
    {
        return [
            'body'    => $message->getBody(),
            'headers' => array_merge($message->getHeaders(), $headers, [
                'Content-Type' => 'application/json',
            ]),
        ];
    }

    private function decodeMessage(array $encoded): Envelope
    {
        $contentType = $encoded['headers']['Content-Type'] ?? null;
        if ($contentType !== 'application/php-serialized') {
            throw new RuntimeException('Unsupported content type for message: ' . $contentType);
        }
        return $this->phpSerializer->decode($encoded);
    }

    private function decodeEvent(array $encoded): Envelope
    {
        $contentType = $encoded['headers']['Content-Type'] ?? null;
        if ($contentType !== 'application/json') {
            throw new RuntimeException('Unsupported content type for event: ' . $contentType);
        }

        $type = $encoded['headers']['X-Event-Name'] ?? null;
        $payload = json_decode($encoded['body'], true);

        return new Envelope(
            new EventReceiveMessage($type, $payload, $encoded['headers'])
        );
    }

    private function decodeRequest(array $encoded): Envelope
    {
        $contentType = $encoded['headers']['Content-Type'] ?? null;
        if ($contentType !== 'application/json') {
            throw new RuntimeException('Unsupported content type for RPC: ' . $contentType);
        }

        return new Envelope(
            new RequestMessage($encoded['body'], $encoded['headers'])
        );
    }

    private function decodeResponse(array $encoded): Envelope
    {
        $contentType = $encoded['headers']['Content-Type'] ?? null;
        if ($contentType !== 'application/json') {
            throw new RuntimeException('Unsupported content type for RPC: ' . $contentType);
        }

        return new Envelope(
            new ResponseMessage($encoded['body'], $encoded['headers'])
        );
    }

    private function getHeaders(Envelope $envelope): array
    {
        $headers = [];

        $amqpStamp = $envelope->last(AmqpStamp::class);
        $amqpHeaders = $amqpStamp?->getAttributes()['application_headers'] ?? [];
        if ($amqpHeaders instanceof AMQPTable) {
            $headers[] = $amqpHeaders->getNativeData();
        } else {
            $headers[] = $amqpHeaders;
        }

        /** @var HeaderStamp[] $headerStamps */
        $headerStamps = $envelope->all(HeaderStamp::class);
        foreach ($headerStamps as $stamp) {
            $headers[] = $stamp->getHeaders();
        }

        return array_merge(...$headers);
    }
}
