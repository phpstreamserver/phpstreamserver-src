<?php

declare(strict_types=1);

namespace PHPStreamServer\Plugin\Supervisor\Message;

use PHPStreamServer\MessageBus\MessageInterface;

/**
 * Process spawned
 * @implements MessageInterface<null>
 */
final readonly class ProcessSpawnedEvent implements MessageInterface
{
    public function __construct(
        public int $workerId,
        public int $pid,
        public string $user,
        public string $name,
        public bool $reloadable,
        public \DateTimeImmutable $startedAt,
    ) {
    }
}
