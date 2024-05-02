<?php

namespace Djereg\Symfony\RabbitMQ\DependencyInjection;

use Djereg\Symfony\RabbitMQ\Attribute\AsMessageEventListener;
use Djereg\Symfony\RabbitMQ\Attribute\AsRemoteProcedure;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class RabbitMQExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $locator = new FileLocator([__DIR__ . '/../Resources/config']);
        $loader = new YamlFileLoader($container, $locator);
        $loader->load('services.yaml');

        $container->registerAttributeForAutoconfiguration(
            AsRemoteProcedure::class,
            static function (
                ChildDefinition $definition,
                AsRemoteProcedure $attribute,
                ReflectionClass|ReflectionMethod $reflector,
            ) {
                $tagAttributes = get_object_vars($attribute);
                if ($reflector instanceof ReflectionMethod) {
                    if (isset($tagAttributes['method'])) {
                        throw new LogicException(
                            sprintf('AsRemoteProcedure attribute cannot declare a method on "%s::%s()".', $reflector->class, $reflector->name)
                        );
                    }
                    $tagAttributes['method'] = $reflector->getName();
                }

                $definition->addTag('rabbitmq.rpc.remote_procedure', $tagAttributes);
            }
        );

        $container->registerAttributeForAutoconfiguration(
            AsMessageEventListener::class,
            static function (
                ChildDefinition $definition,
                AsMessageEventListener $attribute,
                ReflectionClass|ReflectionMethod $reflector
            ) {
                $tagAttributes = get_object_vars($attribute);
                if ($reflector instanceof ReflectionMethod) {
                    if (isset($tagAttributes['method'])) {
                        throw new LogicException(sprintf('AsEventListener attribute cannot declare a method on "%s::%s()".', $reflector->class, $reflector->name));
                    }
                    $tagAttributes['method'] = $reflector->getName();
                }
                $definition->addTag('kernel.event_listener', $tagAttributes);
                $definition->addTag('rabbitmq.event.event_listener', $tagAttributes);
            });
    }
}
