<?php

namespace Djereg\Symfony\RabbitMQ\Service;

use Closure;
use Datto\JsonRpc\Evaluator as EvaluatorInterface;
use Datto\JsonRpc\Exceptions\ApplicationException;
use Datto\JsonRpc\Exceptions\Exception as JsonRpcException;
use Djereg\Symfony\RabbitMQ\Exception\MethodException;
use RuntimeException;
use Throwable;

class Evaluator implements EvaluatorInterface
{
    private array $methods = [];
    private array $optimized = [];

    public function addMethod(string $name, array $method): void
    {
        if (isset($this->methods[$name])) {
            throw new RuntimeException('Method already defined');
        }
        $this->methods[$name] = $method;
    }

    /**
     * @param $method
     * @param $arguments
     *
     * @return mixed
     * @throws MethodException
     * @throws ApplicationException
     * @throws JsonRpcException
     */
    public function evaluate($method, $arguments): mixed
    {
        if (!isset($this->methods[$method])) {
            throw new MethodException('Method not found');
        }
        if (empty($this->optimized[$method])) {
            $this->optimizeMethod($method);
        }
        try {
            return $this->optimized[$method](...$arguments);
        } catch (JsonRpcException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ApplicationException($e->getMessage(), $e->getCode());
        }
    }

    private function optimizeMethod(string $methodName): void
    {
        $this->optimized[$methodName] = null;

        $method = &$this->methods[$methodName];
        $closure = &$this->optimized[$methodName];

        $closure = static function (...$args) use (&$method, &$closure) {
            if ($method[0] instanceof Closure) {
                $method[0] = $method[0]();
                $method[1] ??= '__invoke';
            }
            return ($closure = $method(...))(...$args);
        };
    }
}
