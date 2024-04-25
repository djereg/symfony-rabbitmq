<?php

namespace Djereg\Symfony\RabbitMQ\Core\Event;

use Djereg\Symfony\RabbitMQ\Core\Worker;

final class WorkerRunningEvent
{
    private Worker $worker;
    private bool $isWorkerIdle;

    public function __construct(Worker $worker, bool $isWorkerIdle)
    {
        $this->worker = $worker;
        $this->isWorkerIdle = $isWorkerIdle;
    }

    public function getWorker(): Worker
    {
        return $this->worker;
    }

    /**
     * Returns true when no message has been received by the worker.
     */
    public function isWorkerIdle(): bool
    {
        return $this->isWorkerIdle;
    }
}
