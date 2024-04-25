<?php

namespace Djereg\Symfony\RabbitMQ\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class RegisterListenerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $taggedServices = $container->findTaggedServiceIds($tag = 'rabbitmq.event.event_listener', true);

        $listenedEvents = [];

        foreach ($taggedServices as $events) {
            foreach ($events as $event) {
                $listenedEvents[] = $event['event'];
            }
        }

        $listenedEvents = array_unique($listenedEvents);
        $listenedEvents = array_values($listenedEvents);

        $consumer = $container->getDefinition('console.command.rabbitmq_consume_messages');
        $consumer->setArgument('$listenedEvents', $listenedEvents);
    }
}
