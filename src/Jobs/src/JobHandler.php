<?php

declare(strict_types=1);

namespace Spiral\Jobs;

use Spiral\Core\ResolverInterface;
use Spiral\Jobs\Exception\JobException;

/**
 * Handler which can invoke itself.
 */
abstract class JobHandler implements HandlerInterface
{
    /**
     * Default function with method injection.
     *
     * @var string
     */
    protected const HANDLE_FUNCTION = 'invoke';

    /**
     * @var ResolverInterface
     */
    protected ResolverInterface $resolver;

    /**
     * @param ResolverInterface $resolver
     */
    public function __construct(ResolverInterface $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * @inheritdoc
     */
    public function handle(string $name, string $id, array $payload): void
    {
        $method = new \ReflectionMethod($this, $this->getHandlerMethod());
        $method->setAccessible(true);

        try {
            $parameters = \array_merge(['payload' => $payload, 'id' => $id], $payload);
            $method->invokeArgs($this, $this->resolver->resolveArguments($method, $parameters));
        } catch (\Throwable $e) {
            $message = \sprintf('[%s] %s', \get_class($this), $e->getMessage());
            throw new JobException($message, (int)$e->getCode(), $e);
        }
    }

    /**
     * @return string
     */
    protected function getHandlerMethod(): string
    {
        return static::HANDLE_FUNCTION;
    }
}
