<?php

namespace Djereg\Symfony\RabbitMQ\Response;

use Djereg\Symfony\RabbitMQ\Exception\RequestException;
use Djereg\Symfony\RabbitMQ\Exception\ValidationException;

readonly class Response
{
    public function __construct(
        public mixed $id,
        private mixed $value = null,
        private ?array $error = null,
    ) {
        //
    }

    /**
     * @return $this
     * @throws RequestException
     */
    public function throw(): static
    {
        if ($this->error) {
            $code = $this->error['code'] ?? 0;
            $data = $this->error['data'] ?? null;
            $message = $this->error['message'];

            if ($code === -30422) {
                throw new ValidationException($message, $code, null, $data);
            }

            throw new RequestException($message, $code, null, $data);
        }

        return $this;
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function error(): ?array
    {
        return $this->error;
    }
}
