<?php

namespace Djereg\Symfony\RabbitMQ\RPC\Service;

use Datto\JsonRpc\Client as JsonRpcClient;
use Datto\JsonRpc\Responses\ErrorResponse;
use Datto\JsonRpc\Responses\Response as JsonRpcResponse;
use Datto\JsonRpc\Responses\ResultResponse;
use Djereg\Symfony\RabbitMQ\Core\Event\MessageReceivedEvent;
use Djereg\Symfony\RabbitMQ\Core\Event\MessageSendingEvent;
use Djereg\Symfony\RabbitMQ\Core\Stamp\AmqpStamp;
use Djereg\Symfony\RabbitMQ\Core\Stamp\HeaderStamp;
use Djereg\Symfony\RabbitMQ\Core\Transport\AmqpTransport;
use Djereg\Symfony\RabbitMQ\Events\Service\EventDispatcher;
use Djereg\Symfony\RabbitMQ\RPC\Contract\ClientInterface;
use Djereg\Symfony\RabbitMQ\RPC\Exception\RequestException;
use Djereg\Symfony\RabbitMQ\RPC\Exception\ServerException;
use Djereg\Symfony\RabbitMQ\RPC\Message\RequestMessage;
use Djereg\Symfony\RabbitMQ\RPC\Message\ResponseMessage;
use Djereg\Symfony\RabbitMQ\RPC\Response\Response;
use ErrorException;
use Illuminate\Support\Str;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Messenger\Envelope;

readonly class Client implements ClientInterface
{
    private JsonRpcClient $client;

    public function __construct(
        private string $queue,
        private AmqpTransport $transport,
        private EventDispatcher $dispatcher,
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
        $body = $this->client->encode();
        $request = new RequestMessage($body);

        $envelope = new Envelope($request, [
            new AmqpStamp('', $this->queue, [
                'delivery_mode'  => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'correlation_id' => (string)Str::uuid(),
                'reply_to'       => 'amq.rabbitmq.reply-to',
                'expiration'     => $timeout * 1000,
            ]),
            new HeaderStamp([
                'X-Message-Type' => 'request',
            ]),
        ]);

        // Trigger the event to allow modification of the envelope before sending
        $envelope = $this->dispatchSendingEvent($envelope);

        // Send the request
        $envelope = $this->transport->request($envelope);

        // Trigger the event to allow modification of the envelope after receiving
        $envelope = $this->dispatchReceivedEvent($envelope);

        /** @var ResponseMessage $message */
        $message = $envelope->getMessage();

        try {
            $response = $this->client->decode($message->getBody());
        } catch (ErrorException $e) {
            throw new ServerException('Failed to decode the response.', $e);
        }

        return array_map(fn($x) => $this->mapResponse($x), $response);
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

    private function dispatchSendingEvent(Envelope $envelope): Envelope
    {
        $event = new MessageSendingEvent($envelope);
        $this->dispatcher->dispatch($event);
        return $event->getEnvelope();
    }

    private function dispatchReceivedEvent(Envelope $envelope): Envelope
    {
        $event = new MessageReceivedEvent($envelope);
        $this->dispatcher->dispatch($event);
        return $event->getEnvelope();
    }
}
