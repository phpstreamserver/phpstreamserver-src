<?php

declare(strict_types=1);

namespace PHPStreamServer\MessageBus\Message;

use PHPStreamServer\MessageBus\MessageInterface;

/**
 * @implements MessageInterface<null>
 */
final readonly class StopServerCommand implements MessageInterface
{
    public function __construct(public int $code = 0)
    {
    }
}
