<?php

declare(strict_types=1);

namespace PHPStreamServer\Plugin\System\Message;

use PHPStreamServer\MessageBus\MessageInterface;

/**
 * @implements MessageInterface<null>
 */
final readonly class ConnectionClosedEvent implements MessageInterface
{
    public function __construct(
        public int $pid,
        public int $connectionId,
    ) {
    }
}
