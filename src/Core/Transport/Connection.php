<?php

namespace Djereg\Symfony\RabbitMQ\Core\Transport;

use Djereg\Symfony\RabbitMQ\Core\Stamp\AmqpStamp;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Exception\LogicException;

class Connection
{
    private const ARGUMENTS_AS_INTEGER = [
        'x-delay',
        'x-expires',
        'x-max-length',
        'x-max-length-bytes',
        'x-max-priority',
        'x-message-ttl',
    ];

    private const AVAILABLE_OPTIONS = [
        'host',
        'port',
        'vhost',
        'user',
        'password',
        'queue',
        'exchange',
        'heartbeat',
        'read_timeout',
        'write_timeout',
        'connect_timeout',
        'channel_rpc_timeout',
        'cacert',
        'cert',
        'key',
        'verify',
        'connection_name',
        'lazy',
    ];

    private const AVAILABLE_QUEUE_OPTIONS = [
        'name',
        'binding_keys',
        'binding_arguments',
        'arguments',
    ];

    private const AVAILABLE_EXCHANGE_OPTIONS = [
        'name',
        'type',
        'default_publish_routing_key',
        'arguments',
    ];

    private AmqpFactory $amqpFactory;

    protected ?AbstractConnection $connection = null;

    protected ?AMQPChannel $channel = null;

    /**
     * List of already declared exchanges.
     */
    protected array $exchanges = [];

    /**
     * List of already declared queues.
     */
    protected array $queues = [];

    public function __construct(
        private readonly array $connectionOptions,
        private readonly array $exchangeOptions,
        private readonly array $queueOptions,
        ?AmqpFactory $amqpFactory = null
    ) {
        $this->amqpFactory = $amqpFactory ?? new AmqpFactory();
    }

    /**
     * Creates a connection based on the DSN and options.
     *
     * Available options:
     *
     *   * host: Hostname of the AMQP service
     *   * port: Port of the AMQP service
     *   * vhost: Virtual Host to use with the AMQP service
     *   * user: Username to use to connect the AMQP service
     *   * password: Password to use to connect to the AMQP service
     *   * read_timeout: Timeout in for income activity. Note: 0 or greater seconds. May be fractional.
     *   * write_timeout: Timeout in for outcome activity. Note: 0 or greater seconds. May be fractional.
     *   * connect_timeout: Connection timeout. Note: 0 or greater seconds. May be fractional.
     *   * confirm_timeout: Timeout in seconds for confirmation, if none specified transport will not wait for message confirmation. Note: 0 or greater seconds. May be fractional.
     *   * queues[name]: An array of queues, keyed by the name
     *     * binding_keys: The binding keys (if any) to bind to this queue
     *     * binding_arguments: Arguments to be used while binding the queue.
     *     * flags: Queue flags (Default: AMQP_DURABLE)
     *     * arguments: Extra arguments
     *   * exchange:
     *     * name: Name of the exchange
     *     * type: Type of exchange (Default: fanout)
     *     * default_publish_routing_key: Routing key to use when publishing, if none is specified on the message
     *     * flags: Exchange flags (Default: AMQP_DURABLE)
     *     * arguments: Extra arguments
     *   * delay:
     *     * queue_name_pattern: Pattern to use to create the queues (Default: "delay_%exchange_name%_%routing_key%_%delay%")
     *     * exchange_name: Name of the exchange to be used for the delayed/retried messages (Default: "delays")
     *   * auto_setup: Enable or not the auto-setup of queues and exchanges (Default: true)
     *
     *   * Connection tuning options (see http://www.rabbitmq.com/amqp-0-9-1-reference.html#connection.tune for details):
     *     * channel_max: Specifies highest channel number that the server permits. 0 means standard extension limit
     *       (see PHP_AMQP_MAX_CHANNELS constant)
     *     * frame_max: The largest frame size that the server proposes for the connection, including frame header
     *       and end-byte. 0 means standard extension limit (depends on librabbimq default frame size limit)
     *     * heartbeat: The delay, in seconds, of the connection heartbeat that the server wants.
     *       0 means the server does not want a heartbeat. Note, librabbitmq has limited heartbeat support,
     *       which means heartbeats checked only during blocking calls.
     *
     *   TLS support (see https://www.rabbitmq.com/ssl.html for details):
     *     * cacert: Path to the CA cert file in PEM format.
     *     * cert: Path to the client certificate in PEM format.
     *     * key: Path to the client key in PEM format.
     *     * verify: Enable or disable peer verification. If peer verification is enabled then the common name in the
     *       server certificate must match the server name. Peer verification is enabled by default.
     */
    public static function fromDsn(#[\SensitiveParameter] string $dsn, array $options = [], ?AmqpFactory $amqpFactory = null): self
    {
        if (false === $params = parse_url($dsn)) {
            // this is a valid URI that parse_url cannot handle when you want to pass all parameters as options
            if (!\in_array($dsn, ['amqp://', 'amqps://'])) {
                throw new InvalidArgumentException('The given AMQP DSN is invalid.');
            }

            $params = [];
        }

        $useAmqps = str_starts_with($dsn, 'amqps://');
        $pathParts = isset($params['path']) ? explode('/', trim($params['path'], '/')) : [];
        $exchangeName = $pathParts[1] ?? 'messages';
        parse_str($params['query'] ?? '', $parsedQuery);
        $port = $useAmqps ? 5671 : 5672;

        $amqpOptions = array_replace_recursive([
            'host'     => $params['host'] ?? 'localhost',
            'port'     => $params['port'] ?? $port,
            'vhost'    => isset($pathParts[0]) ? urldecode($pathParts[0]) : '/',
            'exchange' => [
                'name'                        => $exchangeName,
                'default_publish_routing_key' => '%s',
            ],
            'lazy'     => true,
        ], $options, $parsedQuery);

        self::validateOptions($amqpOptions);

        if (isset($params['user'])) {
            $amqpOptions['user'] = rawurldecode($params['user']);
        }

        if (isset($params['pass'])) {
            $amqpOptions['password'] = rawurldecode($params['pass']);
        }

        if (!isset($amqpOptions['queue']['name'])) {
            $amqpOptions['queue']['name'] = $exchangeName;
        }

        $exchangeOptions = $amqpOptions['exchange'];
        $queueOptions = $amqpOptions['queue'];
        unset($amqpOptions['queue'], $amqpOptions['exchange']);

        if (\is_array($queueOptions['arguments'] ?? false)) {
            $queueOptions['arguments'] = self::normalizeQueueArguments($queueOptions['arguments']);
        }

        if (!$useAmqps) {
            unset($amqpOptions['cacert'], $amqpOptions['cert'], $amqpOptions['key'], $amqpOptions['verify']);
        }

        if ($useAmqps && !self::hasCaCertConfigured($amqpOptions)) {
            throw new InvalidArgumentException('No CA certificate has been provided. Set "amqp.cacert" in your php.ini or pass the "cacert" parameter in the DSN to use SSL. Alternatively, you can use amqp:// to use without SSL.');
        }

        return new self($amqpOptions, $exchangeOptions, $queueOptions, $amqpFactory);
    }

    private static function validateOptions(array $options): void
    {
        if (0 < \count($invalidOptions = array_diff(array_keys($options), self::AVAILABLE_OPTIONS))) {
            throw new LogicException(sprintf('Invalid option(s) "%s" passed to the AMQP Messenger transport.', implode('", "', $invalidOptions)));
        }

        if (\is_array($options['queue'] ?? false)
            && 0 < \count($invalidQueueOptions = array_diff(array_keys($options['queue']), self::AVAILABLE_QUEUE_OPTIONS))) {
            throw new LogicException(sprintf('Invalid queue option(s) "%s" passed to the AMQP Messenger transport.', implode('", "', $invalidQueueOptions)));
        }

        if (\is_array($options['exchange'] ?? false)
            && 0 < \count($invalidExchangeOptions = array_diff(array_keys($options['exchange']), self::AVAILABLE_EXCHANGE_OPTIONS))) {
            throw new LogicException(sprintf('Invalid exchange option(s) "%s" passed to the AMQP Messenger transport.', implode('", "', $invalidExchangeOptions)));
        }
    }

    private static function normalizeQueueArguments(array $arguments): array
    {
        foreach (self::ARGUMENTS_AS_INTEGER as $key) {
            if (!\array_key_exists($key, $arguments)) {
                continue;
            }

            if (!is_numeric($arguments[$key])) {
                throw new InvalidArgumentException(sprintf('Integer expected for queue argument "%s", "%s" given.', $key, get_debug_type($arguments[$key])));
            }

            $arguments[$key] = (int)$arguments[$key];
        }

        return $arguments;
    }

    private static function hasCaCertConfigured(array $amqpOptions): bool
    {
        return (isset($amqpOptions['cacert']) && '' !== $amqpOptions['cacert']) || '' !== \ini_get('amqp.cacert');
    }

    /**
     * Returns an approximate count of the messages in defined queues.
     */
    public function countMessagesInQueues(): int
    {
        $queue = $this->getQueue();

        if (!$this->isQueueExists($queue)) {
            return 0;
        }

        // create a temporary channel, so the main channel will not be closed on exception
        $channel = $this->createChannel();
        [, $size] = $channel->queue_declare($queue, true);
        $channel->close();

        return $size;
    }

    public function publish(string $body, array $headers = [], int $delayInMs = 0, ?AmqpStamp $amqpStamp = null, AMQPChannel $channel = null): void
    {
        $this->clearWhenDisconnected();

        if ($delayInMs) {
            $this->publishWithDelay($body, $headers, $delayInMs, $amqpStamp, $channel);
            return;
        }

        [$destination, $exchange, $exchangeType, $attempts] = $this->publishProperties(null, $amqpStamp);
        $this->declareDestination($destination, $exchange, $exchangeType);

        [$message] = $this->createMessage($body, $headers, $amqpStamp);
        $this->publishBasic($message, $exchange, $destination, true, channel: $channel);
    }

    private function publishWithDelay(string $body, array $headers = [], int $delayInMs = 0, ?AmqpStamp $amqpStamp = null, AMQPChannel $channel = null): void
    {
        $queue = $this->getQueue(null);

        // Create a main queue to handle delayed messages
        [$mainDestination, $exchange, $exchangeType, $attempts] = $this->publishProperties($queue, $amqpStamp);
        $this->declareDestination($mainDestination, $exchange, $exchangeType);

        $destination = $queue . '.delay.' . $delayInMs;
        $this->declareQueue($destination, true, false, $this->getDelayQueueArguments($queue, $delayInMs));

        [$message] = $this->createMessage($body, $headers, $amqpStamp);
        $this->publishBasic($message, '', $destination, true, channel: $channel);
    }

    protected function publishBasic($msg, $exchange = '', $destination = '', $mandatory = false, $immediate = false, $ticket = null, AMQPChannel $channel = null): void
    {
        $channel ??= $this->getChannel();
        $channel->basic_publish($msg, $exchange, $destination, $mandatory, $immediate, $ticket);
    }

    public function ack(AMQPMessage $message): void
    {
        $this->getChannel()->basic_ack($message->getDeliveryTag());
    }

    public function nack(AMQPMessage $message): void
    {
        $this->getChannel()->basic_nack($message->getDeliveryTag());
    }

    public function setup(): void
    {
        $this->setupExchange();
        $this->setupQueue();
        $this->setupBindings();
    }

    public function bind(array $keys): void
    {
        $queue = $this->getQueue();
        $channel = $this->getChannel();
        $exchange = $this->getExchange();

        foreach ($keys as $key) {
            $channel->queue_bind($queue, $exchange, $key);
        }
    }

    private function setupExchange(): void
    {
        $name = $this->getExchange();
        $type = $this->getExchangeType();

        $this->declareExchange($name, $type);
    }

    private function setupQueue(): void
    {
        $name = $this->getQueue();
        $this->declareQueue($name);
    }

    private function setupBindings(): void
    {
        $queue = $this->getQueue();
        $channel = $this->getChannel();
        $exchange = $this->getExchange();

        $channel->queue_bind($queue, $exchange, $queue);
    }

    /**
     * Get the Delay queue arguments.
     */
    protected function getDelayQueueArguments(string $destination, int $ttl): array
    {
        return [
            'x-dead-letter-exchange'    => $this->getExchange(''),
            'x-dead-letter-routing-key' => $this->getRoutingKey($destination),
            'x-message-ttl'             => $ttl,
            'x-expires'                 => $ttl * 2,
        ];
    }

    /**
     * Get the exchange name, or empty string; as default value.
     */
    protected function getExchange(string $exchange = null): string
    {
        return $exchange ?? $this->exchangeOptions['name'];
    }

    /**
     * Get the routing-key for when you use exchanges
     * The default routing-key is the given destination.
     */
    protected function getRoutingKey(string $destination): string
    {
        return ltrim(sprintf($this->getDefaultPublishRoutingKey(), $destination), '.');
    }

    protected function getExchangeType(string $type = null): string
    {
        $constant = AMQPExchangeType::class . '::' . strtoupper($type ?: $this->exchangeOptions['type']);

        return defined($constant) ? constant($constant) : AMQPExchangeType::DIRECT;
    }

    /**
     * @return string[]
     */
    public function getQueueNames(): array
    {
        return [$this->getQueue()];
    }

    protected function getConnection(): AbstractConnection
    {
        return $this->connection ??= $this->amqpFactory->createConnection($this->connectionOptions);
    }

    public function getChannel(bool $forceNew = false): AMQPChannel
    {
        if (!$this->channel || $forceNew) {
            $this->channel = $this->createChannel();
        }

        return $this->channel;
    }

    public function createChannel(): AMQPChannel
    {
        return $this->getConnection()->channel();
    }

    private function clearWhenDisconnected(): void
    {
        if (!$this->getChannel()->getConnection()->isConnected()) {
            $this->clear();
        }
    }

    private function clear(): void
    {
        unset($this->amqpChannel, $this->amqpExchange, $this->amqpDelayExchange);
        // $this->amqpQueues = [];
    }

    private function getDefaultPublishRoutingKey(): ?string
    {
        // return $this->queueOptions['name'] ?? null;
        return $this->exchangeOptions['default_publish_routing_key'] ?? null;
    }

    public function purgeQueues(): void
    {
        foreach ($this->getQueueNames() as $queueName) {
            $this->getChannel()->queue_purge($queueName);
        }
    }

    protected function isExchangeDeclared(string $name): bool
    {
        return in_array($name, $this->exchanges, true);
    }

    /**
     * Checks if the queue was already declared.
     */
    protected function isQueueDeclared(string $name): bool
    {
        return in_array($name, $this->queues, true);
    }

    public function isExchangeExists(string $exchange): bool
    {
        if ($this->isExchangeDeclared($exchange)) {
            return true;
        }

        try {
            // create a temporary channel, so the main channel will not be closed on exception
            $channel = $this->createChannel();
            $channel->exchange_declare($exchange, '', true);
            $channel->close();

            $this->exchanges[] = $exchange;

            return true;
        } catch (AMQPProtocolChannelException $exception) {
            if ($exception->amqp_reply_code === 404) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * Declare an exchange in rabbitMQ, when not already declared.
     */
    public function declareExchange(
        string $name,
        string $type = AMQPExchangeType::DIRECT,
        bool $durable = true,
        bool $autoDelete = false,
        array $arguments = []
    ): void {
        if ($this->isExchangeDeclared($name)) {
            return;
        }

        $args = new AMQPTable($arguments);

        $this->getChannel()->exchange_declare($name, $type, false, $durable, $autoDelete, false, true, $args);
    }

    /**
     * Delete an exchange from rabbitMQ, only when present in RabbitMQ.
     *
     *
     * @throws AMQPProtocolChannelException
     */
    public function deleteExchange(string $name, bool $unused = false): void
    {
        if (!$this->isExchangeExists($name)) {
            return;
        }

        $idx = array_search($name, $this->exchanges);
        unset($this->exchanges[$idx]);

        $this->getChannel()->exchange_delete($name, $unused);
    }

    public function getQueue($queue = null): string
    {
        return $queue ?: $this->queueOptions['name'];
    }

    /**
     * Checks if the given queue already present/defined in RabbitMQ.
     * Returns false when the queue is missing.
     *
     *
     * @throws AMQPProtocolChannelException
     */
    public function isQueueExists(string $name = null): bool
    {
        $queueName = $this->getQueue($name);

        if ($this->isQueueDeclared($queueName)) {
            return true;
        }

        try {
            // create a temporary channel, so the main channel will not be closed on exception
            $channel = $this->createChannel();
            $channel->queue_declare($queueName, true);
            $channel->close();

            $this->queues[] = $queueName;

            return true;
        } catch (AMQPProtocolChannelException $exception) {
            if ($exception->amqp_reply_code === 404) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * Declare a queue in rabbitMQ, when not already declared.
     */
    public function declareQueue(string $name, bool $durable = true, bool $autoDelete = false, array $arguments = []): void
    {
        if ($this->isQueueDeclared($name)) {
            return;
        }

        $args = new AMQPTable($arguments);

        $this->getChannel()->queue_declare($name, false, $durable, false, $autoDelete, false, $args);
    }

    protected function declareDestination(string $destination, string $exchange = null, string $exchangeType = AMQPExchangeType::DIRECT): void
    {
        // When an exchange is provided and no exchange is present in RabbitMQ, create an exchange.
        if ($exchange && !$this->isExchangeExists($exchange)) {
            $this->declareExchange($exchange, $exchangeType);
        }

        // When an exchange is provided, just return.
        if ($exchange) {
            return;
        }

        // When the queue already exists, just return.
        if ($this->isQueueExists($destination)) {
            return;
        }

        // Create a queue for amq.direct publishing.
        $this->declareQueue($destination, true, false, []);
        // $this->declareQueue($destination, true, false, $this->getQueueArguments($destination));
    }

    protected function publishProperties($queue, ?AmqpStamp $amqpStamp = null): array
    {
        $queue = $this->getQueue($queue);
        $attempts = 0; // $options['attempts'] ?? 0;

        $destination = $this->getRoutingKey($amqpStamp?->getRoutingKey() ?? $queue);
        $exchange = $this->getExchange($amqpStamp ? $amqpStamp->getExchange() : '');
        $exchangeType = $this->getExchangeType();

        return [$destination, $exchange, $exchangeType, $attempts];
    }

    protected function createMessage($payload, array $headers = [], ?AmqpStamp $amqpStamp = null): array
    {
        $correlationId = null;

        $properties = [
            'content_type'  => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];

        $attributes = $amqpStamp?->getAttributes() ?? [];

        $applicationHeaders = $attributes['application_headers'] ?? [];
        if ($applicationHeaders instanceof AMQPTable) {
            $applicationHeaders = $applicationHeaders->getNativeData();
        }

        $headers = array_merge($applicationHeaders, $headers);
        $properties = array_merge($properties, $attributes, [
            'application_headers' => new AMQPTable($headers),
        ]);

        $message = new AMQPMessage($payload, $properties);

        return [$message, $correlationId];
    }
}
