<?php

namespace Djereg\Symfony\RabbitMQ\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class AsRemoteProcedure
{
    public function __construct(
        public ?string $name = null,
        public ?string $method = null,
    ) {
        //
    }
}
