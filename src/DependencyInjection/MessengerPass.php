<?php

namespace Djereg\Symfony\RabbitMQ\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @author Samuel Roze <samuel.roze@gmail.com>
 */
class MessengerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $d1 = $container->getDefinition('console.command.messenger_consume_messages');
        $d2 = $container->getDefinition('console.command.rabbitmq_consume_messages');

        $d2->setArguments($d1->getArguments());
    }
}
