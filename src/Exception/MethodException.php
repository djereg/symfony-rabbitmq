<?php

namespace Djereg\Symfony\RabbitMQ\Exception;

use Datto\JsonRpc\Exceptions\Exception;
use Datto\JsonRpc\Responses\ErrorResponse;

class MethodException extends Exception
{
    public function __construct(string $message)
    {
        parent::__construct($message, ErrorResponse::INVALID_METHOD);
    }
}
