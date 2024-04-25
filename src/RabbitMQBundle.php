<?php

namespace Djereg\Symfony\RabbitMQ;

use Djereg\Symfony\RabbitMQ\DependencyInjection\MessengerPass;
use Djereg\Symfony\RabbitMQ\DependencyInjection\RegisterClientPass;
use Djereg\Symfony\RabbitMQ\DependencyInjection\RegisterListenerPass;
use Djereg\Symfony\RabbitMQ\DependencyInjection\RegisterProcedurePass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class RabbitMQBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new MessengerPass());
        $container->addCompilerPass(new RegisterListenerPass());
        $container->addCompilerPass(new RegisterProcedurePass());
        $container->addCompilerPass(new RegisterClientPass());
    }
}
