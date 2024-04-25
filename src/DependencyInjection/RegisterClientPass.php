<?php

namespace Djereg\Symfony\RabbitMQ\DependencyInjection;

use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

class RegisterClientPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $taggedServices = $container->findTaggedServiceIds('rabbitmq.rpc.client', true);

        foreach ($taggedServices as $id => $tags) {
            foreach ($tags as $tag) {

                $clientDefinition = $container->getDefinition($id);
                $clientDefinition->setArguments([
                    $tag['queue'],
                    new Reference('messenger.transport.rabbitmq'),
                ]);
            }
        }
    }
}
