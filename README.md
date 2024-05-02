# Symfony RabbitMQ

**THIS PACKAGE IS PRIMARILY INTENDED FOR INTERNAL/PRIVATE USE IN OWN PROJECTS.
IF IT MEETS YOUR NEEDS, FEEL FREE TO USE IT, BUT IN CASE OF ANY MODIFICATION REQUESTS, I WILL CONSIDER MY OWN NEEDS FIRST.**

The package is part of the [rabbitmq-multiverse](https://github.com/djereg/rabbitmq-multiverse).

# Table of Contents

- [Description](#description)
- [Motivation](#motivation)
- [Usage](#usage)
    - [Installation](#installation)
    - [Configuration](#configuration)
    - [Starting the consumer](#starting-the-consumer)
- [Events](#events)
    - [Dispatching events](#dispatching-events)
    - [Listening to events](#listening-to-events)
    - [Errors in listeners](#errors-in-listeners)
    - [How to process an event asynchronously?](#how-to-process-an-event-asynchronously)
    - [Subscribing to events](#subscribing-to-events)
- [RPC](#rpc)
    - [Registering clients](#registering-clients)
    - [Calling remote procedures](#calling-remote-procedures)
    - [Registering remote procedures](#registering-remote-procedures)
- [Symfony Messenger](#symfony-messenger)
- [Lifecycle Events](#lifecycle-events)
    - [MessagePublishingEvent](#messagepublishingevent)
    - [MessageReceivedEvent](#messagereceivedevent)
    - [MessageProcessingEvent](#messageprocessingevent)
    - [MessageProcessedEvent](#messageprocessedevent)
- [Known Issues](#known-issues)
- [License](#license)

# Description

This package is an alternative for [Symfony AMQP Messenger Component](https://symfony.com/components/AMQP%20Messenger). Works very similarly, allows you to start a message consumer and send messages to RabbitMQ.

Extends the functionality of the original [Symfony Messenger](https://symfony.com/doc/current/messenger.html) by adding the ability to send and receive events and RPC queries through RabbitMQ messages.

# Motivation

Since the microservice architecture has become very popular, I needed a library that provides the possibility of communicating with services written in different programming languages or frameworks.

Symfony has a good messaging system, but it is a closed Symfony-only system. This package allows you to communicate through messages between Symfony and/or other non-Symfony microservices.

On the top of simple JSON messages, utilizes the Symfony Messenger system, which perfectly does the rest of the job.

# Usage

## Installation

You can install this package via composer using this command:

```bash
composer require djereg/symfony-rabbitmq
```

## Configuration

First, you have to define the environment variables.

```dotenv
# Set the queue connection to rabbitmq
RABBITMQ_DSN=amqp://guest:guest@rabbitmq:5672/%2f

RABBITMQ_QUEUE_NAME=queue-name
RABBITMQ_EXCHANGE_NAME=exchange-name
RABBITMQ_EXCHANGE_TYPE=direct-name
```

Then you have to add the configuration to the `config/packages/messenger.yaml` file.

```yaml
# config/packages/messenger.yaml

framework:
    messenger:
        transports:

            # The name of the transport must be rabbitmq
            # If the transport is defined with a different name,
            # an exception will be thrown at runtime.
            rabbitmq:
                dsn: '%env(RABBITMQ_DSN)%'
                options:
                    queue:
                        name: '%env(RABBITMQ_QUEUE_NAME)%'
                    exchange:
                        name: '%env(RABBITMQ_EXCHANGE_NAME)%'
                        type: '%env(RABBITMQ_EXCHANGE_TYPE)%'
```

## Starting the consumer

To start the consumer, you have to run the following command.

```bash
php bin/console rabbitmq:consume
```

The consumer will start and listen to the queue for incoming messages.

Most of the options are the same as in the original Symfony Messenger consumer.
Start the consumer with the `-h` option to see all available options.

# Events

Provides an event based asynchronous communication between services.

## Dispatching events

Create an event class that extends the `MessagePublishEvent` class.

```php
use Djereg\Symfony\RabbitMQ\Event\MessagePublishEvent;

class UserCreated extends MessagePublishEvent
{
    // Set the event name
    protected string $event = 'user.created';

    public function __construct(private User $user)
    {
        $this->user = $user;
    }

    // Create a payload method that returns the data to be sent
    public function payload(): array
    {
        return [
            'user_id' => $this->user->id,
        ];
    }
}
```

And after just dispatch the event like any other Symfony event.

Almost, just a little difference. Instead of the Symfony event dispatcher, you have to use the `EventDispatcher` included in this package.

Since the Symfony event system does not support listening to interfaces on top of many events, the `EventDispatcher` does the trick by calling the Symfony event dispatcher under the hood and pass the full name of `MessagePublishEvent` and a listener
listening to this event will catch all events implementing this interface.

```php
use Djereg\Symfony\RabbitMQ\Service\EventDispatcher;

class UserService
{
    public function __construct(
        private EventDispatcher $dispatcher
    ) {
        //
    }

    public function createUser(User $user): void
    {
        // Dispatch the event
        $this->dispatcher->dispatch(new UserCreated($user));
    }
}
```

That's it, it's not so complicated.

## Listening to events

Create an event listener class and add the `AsMessageEventListener` attribute like in the example below.

You have to define the event name in the attribute. The event name must be the same as the event name defined in the event object.

The attribute behaves exactly like the Symfony event listener attribute, but adds one more tag to the service, what helps to collect the events listening to.
The name differs from the Symfony attribute to avoid the confusion about which event system is used.

```php
use Djereg\Symfony\RabbitMQ\Attribute\AsMessageEventListener;

#[AsMessageEventListener('user.created')]
class NotifyUserListener
{
    public function __invoke(MessageEvent $event): void
    {
        // Do something
    }
}
```

See more about the event listeners at [Symfony docs](https://symfony.com/doc/current/event_dispatcher.html#event-listeners). The only one thing you have to remember is to define the event name in the listener.

## Errors in listeners

When an unhandled error occurs in a listener, the message will be requeued and the event will be dispatched again.
This will happen until the message is successfully processed or the maximum number of attempts is reached.
If multiple listeners are listening to the same event, the processing will stop at the first listener that throws an exception and the rest of the listeners will not be called.

Preventing this behavior there are two ways.
The first one is to catch the exception in the listener and handle it.
The second one is to listen to events and put messages to the queue and handle them separately and asynchronously.
This way the failed message will not block the rest of the messages.

## How to process an event asynchronously?

Oh, it's very simple! You need an intermediate listener that will put a message to the queue automatically and a message handler that will handle the message.

First create a message that extends `EventMessage`. This message will be sent to the queue and will be processed by the message handler.

```php
use Djereg\Symfony\RabbitMQ\Message\EventMessage;

class UserCreatedMessage extends EventMessage {}
```

Then create an event listener that extends `MessageEventListener`. This listener will listen to the event and put the message to the queue automatically.

```php
use Djereg\Symfony\RabbitMQ\Attribute\AsMessageEventListener;
use Djereg\Symfony\RabbitMQ\Listeners\MessageEventListener;

#[AsMessageEventListener('user.created')]
class UserCreatedListener extends MessageEventListener
{
    protected string $message = UserCreatedMessage::class;
}
```

Finally, create a message handler that will handle the message put to the queue.

```php
#[AsMessageHandler]
class UserCreatedMessageHandler
{
    public function __invoke(UserCreatedMessage $message): void {

        // Get the event name
        $event = $message->getEvent();

        // Get the event payload
        $payload = $event->getPayload();

        // Get the event wrapped in the message
        $raw = $message->getMessageEvent();
    }
}
```

It's pretty simple, right? I know, not really. But it works.

## Subscribing to events

The consumer automatically creates the exchange and the queue if they do not exist and registers all listened events as bindings keys to the queue.

# RPC

A synchronous-like communication between services.

Uses the [JSON-RPC 2.0](https://www.jsonrpc.org/specification) protocol for communication.

## Registering clients

To call remote procedures, you have to create an instance of the `Client` class and register it in the service container.

```yaml
# config/services.yaml

services:
    users_client:
        class: Djereg\Symfony\RabbitMQ\Service\Client
        tags:
            -   name: rabbitmq.rpc.client
                queue: users

    # Some example client definitions below
    orders_client:
        class: Djereg\Symfony\RabbitMQ\Service\Client
        tags:
            -   name: rabbitmq.rpc.client
                queue: orders

    products_client:
        class: Djereg\Symfony\RabbitMQ\Service\Client
        tags:
            -   name: rabbitmq.rpc.client
                queue: products
```

## Calling remote procedures

Create a service and inject the client into it.

```php
use Djereg\Symfony\RabbitMQ\Contract\ClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class UserService
{
    public function __construct(
        #[Autowire('users_client')]
        private ClientInterface $client,
    ) {
        //
    }

    public function getUser(int $id): User
    {
        // Call the remote procedure
        $user = $this->client->call('get', ['id' => $id]);

        // Return the user or do something else with it
        return $user;
    }
}
```

## Registering remote procedures

Create a service and add the `AsRemoteProcedure` attribute.

Works very similarly to the event listeners described above. You can add the attribute to a class or to a method.

```php
use Djereg\Symfony\RabbitMQ\Attribute\AsRemoteProcedure;

#[AsRemoteProcedure('get')]
class GetUser
{
    public function __invoke(int $id): array
    {
        // Query the database and return the result
    }
}
```

Or another example with the attribute added to a method.

```php
use Djereg\Symfony\RabbitMQ\Attribute\AsRemoteProcedure;

class UserService
{
    #[AsRemoteProcedure('get')]
    public function getUser(int $id): array
    {
        // Query the database and return the result
    }

    // When adding the attribute to a method, you can omit the name of the procedure.
    // In this case, the name will be the same as the method name.
    #[AsRemoteProcedure]
    public function update(int $id, array $data): bool
    {
        // Update the user and return the result of the operation
    }
}
```

When registering two or more procedures with the same name, an exception will be thrown at startup.

# Symfony Messenger

The functionality of the original Symfony Messenger component is also available.
[Route the messages](https://symfony.com/doc/current/messenger.html#routing-messages-to-a-transport) to the rabbitmq transport, and they will be sent to the queue and processed by the consumer.

# Lifecycle Events

## MessagePublishingEvent

Dispatched before the message is sent to the queue.

```php
use Djereg\Symfony\RabbitMQ\Event\MessagePublishingEvent;
```

## MessageReceivedEvent

Dispatched when the message is received from the queue.

```php
use Djereg\Symfony\RabbitMQ\Event\MessageReceivedEvent;
```

## MessageProcessingEvent

Dispatched when the message is being processed.

```php
use Djereg\Symfony\RabbitMQ\Event\MessageProcessingEvent;
```

## MessageProcessedEvent

Dispatched when the message is processed.

```php
use Djereg\Symfony\RabbitMQ\Event\MessageProcessedEvent;
```

# Known Issues

- NO TESTS! I know, I know. I will write them soon.

# License

[MIT licensed](LICENSE)
