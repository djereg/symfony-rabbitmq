<?php

namespace Djereg\Symfony\RabbitMQ\Transport;

use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;

class AmqpFactory
{
    public function createConnection(array $options): AbstractConnection
    {
        $config = new AMQPConnectionConfig();

        if (isset($options['host'])) {
            $config->setHost($options['host']);
        }
        if (isset($options['port'])) {
            $config->setPort($options['port']);
        }
        if (isset($options['vhost'])) {
            $config->setVhost($options['vhost']);
        }
        if (isset($options['user'])) {
            $config->setUser($options['user']);
        }
        if (isset($options['password'])) {
            $config->setPassword($options['password']);
        }
        if (isset($options['heartbeat'])) {
            $config->setHeartbeat($options['heartbeat']);
        }
        if (isset($options['read_timeout'])) {
            $config->setReadTimeout($options['read_timeout']);
        }
        if (isset($options['write_timeout'])) {
            $config->setWriteTimeout($options['write_timeout']);
        }
        if (isset($options['connect_timeout'])) {
            $config->setConnectionTimeout($options['connect_timeout']);
        }
        if (isset($options['channel_rpc_timeout'])) {
            $config->setChannelRPCTimeout($options['channel_rpc_timeout']);
        }
        if (isset($options['cacert'])) {
            $config->setSslCaCert($options['cacert']);
        }
        if (isset($options['cert'])) {
            $config->setSslCert($options['cert']);
        }
        if (isset($options['key'])) {
            $config->setSslKey($options['key']);
        }
        if (isset($options['verify'])) {
            $config->setSslVerify($options['verify']);
        }
        if (isset($options['connection_name'])) {
            $config->setConnectionName($options['connection_name']);
        }
        if (isset($options['lazy'])) {
            $config->setIsLazy($options['lazy']);
        }

        return AMQPConnectionFactory::create($config);
    }
}
