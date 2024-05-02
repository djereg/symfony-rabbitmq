<?php

namespace Djereg\Symfony\RabbitMQ\Contract;

interface ClientInterface
{
    public function call(string $method, array $arguments, int $timeout = 30): mixed;

    public function query(string|int $id, string $method, array $arguments = []): static;

    public function notify(string $method, array $arguments = []): static;

    public function send(int $timeout = 30): array;
}
