<?php

namespace Djereg\Symfony\RabbitMQ\Stamp;

use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

final class AmqpStamp implements NonSendableStampInterface
{
    private bool $isRetryAttempt = false;

    public function __construct(
        private readonly ?string $exchange = null,
        private readonly ?string $routingKey = null,
        private readonly array $attributes = []
    ) {
        //
    }

    public function getExchange(): ?string
    {
        return $this->exchange;
    }

    public function getRoutingKey(): ?string
    {
        return $this->routingKey;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public static function createFromAmqpMessage(AMQPMessage $amqpMessage, ?AmqpStamp $previousStamp = null, ?string $retryRoutingKey = null): self
    {
        $attr = $previousStamp->attributes ?? [];

        $exchange = $amqpMessage->getExchange();
        $properties = $amqpMessage->get_properties();

        $attr['application_headers'] ??= $properties['application_headers'] ?? [];
        $attr['content_type'] ??= $properties['content_type'] ?? null;
        $attr['content_encoding'] ??= $properties['content_encoding'] ?? null;
        $attr['delivery_mode'] ??= $properties['delivery_mode'] ?? null;
        $attr['priority'] ??= $properties['priority'] ?? null;
        $attr['timestamp'] ??= $properties['timestamp'] ?? null;
        $attr['app_id'] ??= $properties['app_id'] ?? null;
        $attr['message_id'] ??= $properties['message_id'] ?? null;
        $attr['user_id'] ??= $properties['user_id'] ?? null;
        $attr['expiration'] ??= $properties['expiration'] ?? null;
        $attr['type'] ??= $properties['type'] ?? null;
        $attr['reply_to'] ??= $properties['reply_to'] ?? null;
        $attr['correlation_id'] ??= $properties['correlation_id'] ?? null;

        if (null === $retryRoutingKey) {
            $routingKey = $previousStamp->routingKey ?? $amqpMessage->getRoutingKey();
            $stamp = new self($exchange, $routingKey, $attr);
        } else {
            $stamp = new self($exchange, $retryRoutingKey, $attr);
            $stamp->isRetryAttempt = true;
        }

        return $stamp;
    }

    public function isRetryAttempt(): bool
    {
        return $this->isRetryAttempt;
    }

    public static function createWithAttributes(array $attributes, ?self $previousStamp = null): self
    {
        return new self(
            $previousStamp->exchange ?? null,
            $previousStamp->routingKey ?? null,
            array_merge($previousStamp->attributes ?? [], $attributes)
        );
    }
}
