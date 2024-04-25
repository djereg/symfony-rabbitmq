<?php

namespace Djereg\Symfony\RabbitMQ\Core\Event;

use Djereg\Symfony\RabbitMQ\Core\Worker;

final class WorkerStartedEvent
{
    private Worker $worker;

    public function __construct(Worker $worker)
    {
        $this->worker = $worker;
    }

    public function getWorker(): Worker
    {
        return $this->worker;
    }
}
