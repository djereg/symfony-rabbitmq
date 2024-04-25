<?php

namespace Djereg\Symfony\RabbitMQ\Core\Command;

use Djereg\Symfony\RabbitMQ\Core\Event\MessageReceivedEvent;
use Djereg\Symfony\RabbitMQ\Core\EventListener\ResetServicesListener;
use Djereg\Symfony\RabbitMQ\Core\EventListener\StopWorkerOnFailureLimitListener;
use Djereg\Symfony\RabbitMQ\Core\EventListener\StopWorkerOnMemoryLimitListener;
use Djereg\Symfony\RabbitMQ\Core\EventListener\StopWorkerOnMessageLimitListener;
use Djereg\Symfony\RabbitMQ\Core\EventListener\StopWorkerOnTimeLimitListener;
use Djereg\Symfony\RabbitMQ\Core\Stamp\AmqpReceivedStamp;
use Djereg\Symfony\RabbitMQ\Core\Transport\AmqpTransport;
use Djereg\Symfony\RabbitMQ\Core\Worker;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\RoutableMessageBus;

#[AsCommand(
    name: 'rabbitmq:consume',
    description: 'Consume messages from a transport',
)]
class ConsumeCommand extends Command implements SignalableCommandInterface
{
    private ?Worker $worker = null;

    public function __construct(
        private readonly RoutableMessageBus $routableBus,
        private readonly ServiceLocator $receiverLocator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ?LoggerInterface $logger = null,
        private readonly array $receiverNames = [],
        private readonly ?ResetServicesListener $resetServicesListener = null,
        private readonly array $busIds = [],
        private readonly ?ServiceLocator $rateLimiterLocator = null,
        private readonly ?array $signals = null,
        private readonly ?array $listenedEvents = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDefinition([
                new InputOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit the number of received messages'),
                new InputOption('failure-limit', 'f', InputOption::VALUE_REQUIRED, 'The number of failed messages the worker can consume'),
                new InputOption('memory-limit', 'm', InputOption::VALUE_REQUIRED, 'The memory limit the worker can consume'),
                new InputOption('time-limit', 't', InputOption::VALUE_REQUIRED, 'The time limit in seconds the worker can handle new messages'),
                // new InputOption('bus', 'b', InputOption::VALUE_REQUIRED, 'Name of the bus to which received messages should be dispatched (if not passed, bus is determined automatically)'),
                new InputOption('no-reset', null, InputOption::VALUE_NONE, 'Do not reset container services after each message'),
            ]);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rateLimiter = null;
        $receiverName = 'rabbitmq';

        if (!in_array($receiverName, $this->receiverNames, true)) {
            throw new LogicException('No AMQP transport found by name "rabbitmq" in the configuration.');
        }

        $receiver = $this->receiverLocator->get($receiverName);

        if (!$receiver instanceof AmqpTransport) {
            throw new LogicException('The "rabbitmq" transport must be an instance of AmqpTransport.');
        }

        // Register the rate limiter if it exists
        if ($this->rateLimiterLocator?->has($receiverName)) {
            $rateLimiter = $this->rateLimiterLocator->get($receiverName);
        }

        if (null !== $this->resetServicesListener && !$input->getOption('no-reset')) {
            $this->eventDispatcher->addSubscriber($this->resetServicesListener);
        }

        $stopsWhen = [];
        if (null !== $limit = $input->getOption('limit')) {
            if (!is_numeric($limit) || 0 >= $limit) {
                throw new InvalidOptionException(sprintf('Option "limit" must be a positive integer, "%s" passed.', $limit));
            }

            $stopsWhen[] = "processed {$limit} messages";
            $this->eventDispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($limit, $this->logger));
        }

        if ($failureLimit = $input->getOption('failure-limit')) {
            $stopsWhen[] = "reached {$failureLimit} failed messages";
            $this->eventDispatcher->addSubscriber(new StopWorkerOnFailureLimitListener($failureLimit, $this->logger));
        }

        if ($memoryLimit = $input->getOption('memory-limit')) {
            $stopsWhen[] = "exceeded {$memoryLimit} of memory";
            $this->eventDispatcher->addSubscriber(new StopWorkerOnMemoryLimitListener($this->convertToBytes($memoryLimit), $this->logger));
        }

        if (null !== $timeLimit = $input->getOption('time-limit')) {
            if (!is_numeric($timeLimit) || 0 >= $timeLimit) {
                throw new InvalidOptionException(sprintf('Option "time-limit" must be a positive integer, "%s" passed.', $timeLimit));
            }

            $stopsWhen[] = "been running for {$timeLimit}s";
            $this->eventDispatcher->addSubscriber(new StopWorkerOnTimeLimitListener($timeLimit, $this->logger));
        }

        // $stopsWhen[] = 'received a stop signal via the messenger:stop-workers command';

        $io = new SymfonyStyle($input, $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);
        $io->success(sprintf('Consuming messages from transport "%s".', $receiverName));

        if ($stopsWhen) {
            $last = array_pop($stopsWhen);
            $stopsWhen = ($stopsWhen ? implode(', ', $stopsWhen) . ' or ' : '') . $last;
            $io->comment("The worker will automatically exit once it has {$stopsWhen}.");
        }

        $io->comment('Quit the worker with CONTROL-C.');

        if (OutputInterface::VERBOSITY_VERBOSE > $output->getVerbosity()) {
            $io->comment('Re-run the command with a -vv option to see logs about consumed messages.');
        }

        $this->eventDispatcher->addListener(MessageReceivedEvent::class, function (MessageReceivedEvent $event) use ($io) {

            $message = $event->getEnvelope()->last(AmqpReceivedStamp::class)->getAmqpMessage();

            // Get the message headers
            $headers = $message->get('application_headers') ?? [];
            if ($headers instanceof AMQPTable) {
                $headers = $headers->getNativeData();
            }

            // Get the message type
            $type = $headers['X-Message-Type'] ?? null;

            switch ($type) {
                case 'message':
                    $io->text('Received message: ' . ($headers['X-Message-Class'] ?? 'unknown'));
                    break;
                case 'event':
                    $io->text('Received event: ' . ($headers['X-Event-Name'] ?? 'unknown'));
                    break;
                case 'request':
                    $io->text('Received RPC request');
                    break;
            }
        });

        // $bus = $input->getOption('bus') ? $this->routableBus->getMessageBus($input->getOption('bus')) : $this->routableBus;

        $this->worker = new Worker($receiver, $this->routableBus, $this->eventDispatcher, $this->logger, $rateLimiter, $this->listenedEvents);

        try {
            $this->worker->run();
        } finally {
            $this->worker = null;
        }

        return 0;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('receivers')) {
            $suggestions->suggestValues(array_diff($this->receiverNames, array_diff($input->getArgument('receivers'), [$input->getCompletionValue()])));

            return;
        }

        if ($input->mustSuggestOptionValuesFor('bus')) {
            $suggestions->suggestValues($this->busIds);
        }
    }

    public function getSubscribedSignals(): array
    {
        return $this->signals ?? (\extension_loaded('pcntl') ? [\SIGTERM, \SIGINT, \SIGQUIT] : []);
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if (!$this->worker) {
            return false;
        }

        $this->logger?->info('Received signal {signal}.', ['signal' => $signal, 'transport_names' => $this->worker->getMetadata()->getTransportNames()]);
        $this->worker->stop();

        return false;
    }

    private function convertToBytes(string $memoryLimit): int
    {
        $memoryLimit = strtolower($memoryLimit);
        $max = ltrim($memoryLimit, '+');
        if (str_starts_with($max, '0x')) {
            $max = \intval($max, 16);
        } elseif (str_starts_with($max, '0')) {
            $max = \intval($max, 8);
        } else {
            $max = (int)$max;
        }

        switch (substr(rtrim($memoryLimit, 'b'), -1)) {
            case 't':
                $max *= 1024;
            // no break
            case 'g':
                $max *= 1024;
            // no break
            case 'm':
                $max *= 1024;
            // no break
            case 'k':
                $max *= 1024;
        }

        return $max;
    }
}
