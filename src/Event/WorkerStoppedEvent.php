<?php

namespace Djereg\Symfony\RabbitMQ\Event;

use Djereg\Symfony\RabbitMQ\Worker;

final class WorkerStoppedEvent
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
