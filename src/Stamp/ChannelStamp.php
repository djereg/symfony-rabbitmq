<?php

namespace Djereg\Symfony\RabbitMQ\Stamp;

use PhpAmqpLib\Channel\AMQPChannel;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

readonly class ChannelStamp implements NonSendableStampInterface
{
    public function __construct(
        private AMQPChannel $channel,
    ) {
        //
    }

    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }
}
