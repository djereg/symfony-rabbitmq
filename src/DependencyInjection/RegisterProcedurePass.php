<?php

namespace Djereg\Symfony\RabbitMQ\DependencyInjection;

use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

class RegisterProcedurePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $taggedServices = $container->findTaggedServiceIds($tag = 'rabbitmq.rpc.remote_procedure', true);
        $evaluatorDefinition = $container->getDefinition('rabbitmq.rpc.evaluator');

        foreach ($taggedServices as $id => $procedures) {
            foreach ($procedures as $procedure) {

                if (empty($procedure['name']) && empty($procedure['method'])) {
                    throw new InvalidArgumentException('If the AsRemoteProcedure attribute is added to a class, the name parameter must be set.');
                }

                $name = $procedure['name'] ?? $procedure['method'];
                $method = $procedure['method'] ?? $procedure['name'];

                if (
                    ($class = $container->getDefinition($id)->getClass()) &&
                    ($r = $container->getReflectionClass($class, false)) &&
                    !$r->hasMethod($method)
                ) {
                    if (!$r->hasMethod('__invoke')) {
                        throw new InvalidArgumentException(
                            sprintf('None of the "%s" or "__invoke" methods exist for the service "%s". Please define the "method" attribute on "%s" tags.', $method, $class, $tag)
                        );
                    }
                    $method = '__invoke';
                }

                $evaluatorDefinition->addMethodCall('addMethod', [$name, [new ServiceClosureArgument(new Reference($id)), $method]]);
            }
        }
    }
}
