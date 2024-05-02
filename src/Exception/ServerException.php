<?php

namespace Djereg\Symfony\RabbitMQ\Exception;

use Djereg\Symfony\RabbitMQ\Library\ArrayBag;
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
