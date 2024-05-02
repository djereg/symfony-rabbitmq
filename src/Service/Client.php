<?php

namespace Djereg\Symfony\RabbitMQ\Service;

use Datto\JsonRpc\Client as JsonRpcClient;
use Datto\JsonRpc\Responses\ErrorResponse;
use Datto\JsonRpc\Responses\Response as JsonRpcResponse;
use Datto\JsonRpc\Responses\ResultResponse;
use Djereg\Symfony\RabbitMQ\Contract\ClientInterface;
use Djereg\Symfony\RabbitMQ\Event\MessageReceivedEvent;
use Djereg\Symfony\RabbitMQ\Event\MessagePublishingEvent;
use Djereg\Symfony\RabbitMQ\Exception\RequestException;
use Djereg\Symfony\RabbitMQ\Exception\ServerException;
use Djereg\Symfony\RabbitMQ\Exception\TimeoutException;
use Djereg\Symfony\RabbitMQ\Message\RequestMessage;
use Djereg\Symfony\RabbitMQ\Message\ResponseMessage;
use Djereg\Symfony\RabbitMQ\Response\Response;
use Djereg\Symfony\RabbitMQ\Stamp\AmqpReceivedStamp;
use Djereg\Symfony\RabbitMQ\Stamp\AmqpStamp;
use Djereg\Symfony\RabbitMQ\Stamp\ChannelStamp;
use Djereg\Symfony\RabbitMQ\Stamp\HeaderStamp;
use Djereg\Symfony\RabbitMQ\Transport\AmqpTransport;
use ErrorException;
use Illuminate\Support\Str;
use PhpAmqpLib\Channel\AMQPChannel as Channel;
use PhpAmqpLib\Exception\AMQPNoDataException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Messenger\Envelope;

class Client implements ClientInterface
{
    private readonly JsonRpcClient $client;
    private Channel $channel;
    private ?Envelope $response;
    private string $consumerTag;

    public function __construct(
        private readonly string $queue,
        private readonly AmqpTransport $transport,
        private readonly EventDispatcher $dispatcher,
    ) {
        $this->client = new JsonRpcClient();
    }

    /**
     * @param string|int $id The request ID.
     * @param string $method The method to call on the service.
     * @param array $arguments The arguments to pass to the method.
     *
     * @return $this
     */
    public function query(string|int $id, string $method, array $arguments = []): static
    {
        $this->client->query($id, $method, $arguments);
        return $this;
    }

    /**
     * @param string $method The method to call on the service.
     * @param array $arguments The arguments to pass to the method.
     *
     * @return $this
     */
    public function notify(string $method, array $arguments = []): static
    {
        $this->client->notify($method, $arguments);
        return $this;
    }

    /**
     * @param string $method
     * @param array $arguments
     *
     * @return mixed
     * @throws ServerException
     * @throws RequestException
     */
    public function call(string $method, array $arguments, int $timeout = 30): mixed
    {
        $response = $this->query(1, $method, $arguments)->send($timeout)[0];

        return $response->throw()->value();
    }

    /**
     * @return Response[]
     * @throws ServerException
     */
    public function send(int $timeout = 30): array
    {
        $channel = $this->channel();
        $queue = 'amq.rabbitmq.reply-to';
        $body = $this->client->encode();
        $uuid = Str::uuid()->toString();
        $request = new RequestMessage($body);

        $envelope = new Envelope($request, [
            new AmqpStamp('', $this->queue, [
                'delivery_mode'  => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'correlation_id' => $uuid,
                'reply_to'       => $queue,
                'expiration'     => $timeout * 1000,
            ]),
            new HeaderStamp([
                'X-Message-Type' => 'request',
            ]),
            new ChannelStamp($channel),
        ]);

        // Trigger the event to allow modification of the envelope before sending
        $envelope = $this->dispatchPublishingEvent($envelope);

        $this->response = null;

        $this->initConsumer($queue);

        // Send the request
        $this->transport->send($envelope);

        $stopTime = microtime(true) + $timeout;
        $waitTimeout = $this->transport->getWaitTimeout($timeout);

        while ($channel->is_consuming() || $channel->hasPendingMethods()) {

            if ($stopTime < microtime(true)) {
                throw new TimeoutException('The server took too long to respond.');
            }

            try {
                $channel->wait(null, false, $waitTimeout);
            } catch (AMQPTimeoutException $e) {
                // something might be wrong, try to send heartbeat which involves select+write
                $this->transport->heartbeat();
                continue;
            } catch (AMQPNoDataException $e) {
                continue;
            }

            $message = $this->response?->last(AmqpReceivedStamp::class)->getAmqpMessage();

            if ($message?->get('correlation_id') === $uuid) {
                break;
            }
        }

        /** @var ResponseMessage $message */
        $message = $envelope->getMessage();

        try {
            $response = $this->client->decode($message->getBody());
        } catch (ErrorException $e) {
            throw new ServerException('Failed to decode the response.', $e);
        }

        return array_map(fn($x) => $this->mapResponse($x), $response);
    }

    private function channel(): Channel
    {
        return $this->channel ??= $this->transport->channel(true);
    }

    private function initConsumer(string $queue): void
    {
        if (isset($this->consumerTag)) {
            return;
        }

        $channel = $this->channel();

        $handler = function (Envelope $envelope) {
            $this->response = $envelope;
        };

        $this->consumerTag = $this->transport->consumer($handler, $queue, $channel, true);
    }

    private function mapResponse(JsonRpcResponse $response): Response
    {
        if ($response instanceof ErrorResponse) {
            return new Response(
                id: $response->getId(),
                error: [
                    'code'    => $response->getCode(),
                    'message' => $response->getMessage(),
                    'data'    => $response->getData(),
                ],
            );
        }

        assert($response instanceof ResultResponse);

        return new Response(
            id: $response->getId(),
            value: $response->getValue()
        );
    }

    private function dispatchPublishingEvent(Envelope $envelope): Envelope
    {
        $event = new MessagePublishingEvent($envelope);
        $event = $this->dispatcher->dispatch($event);
        return $event->getEnvelope();
    }

    private function dispatchReceivedEvent(Envelope $envelope): Envelope
    {
        $event = new MessageReceivedEvent($envelope);
        $event = $this->dispatcher->dispatch($event);
        return $event->getEnvelope();
    }
}
