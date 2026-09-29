<?php

declare(strict_types=1);

namespace CentralVet\Application\Contract;

/**
 * Boundary shared by UI, REST, CLI and future MCP adapters.
 *
 * @template TCommand of object
 * @template TResult
 */
interface ApplicationServiceInterface
{
    /**
     * @param TCommand $command
     * @return TResult
     */
    public function execute(object $command): mixed;
}
