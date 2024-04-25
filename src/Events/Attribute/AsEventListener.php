<?php

namespace Djereg\Symfony\RabbitMQ\Events\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class AsEventListener
{
    public function __construct(
        public string $event,
        public ?string $method = null,
        public int $priority = 0,
        public ?string $dispatcher = null,
    ) {
        //
    }
}
