<?php

namespace Djereg\Symfony\RabbitMQ\RPC\Exception;

use Djereg\Symfony\RabbitMQ\Events\Library\ArrayBag;
use Throwable;

class ServerException extends ProcedureCallException
{
    private readonly ArrayBag $error;

    public function __construct(string $message, Throwable $previous = null, ?ArrayBag $error = null)
    {
        parent::__construct($message, 0, $previous);
        $this->error = $error ?? new ArrayBag([]);
    }

    public function getError(): ArrayBag
    {
        return $this->error;
    }
}
