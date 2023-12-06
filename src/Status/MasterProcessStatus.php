<?php

declare(strict_types=1);

namespace Luzrain\PhpRunner\Status;

use Luzrain\PhpRunner\Internal\Functions;
use Luzrain\PhpRunner\MasterProcess;
use Luzrain\PhpRunner\PhpRunner;
use Revolt\EventLoop\DriverFactory;

/**
 * @internal
 */
final readonly class MasterProcessStatus
{
    public bool $isRunning;
    public string $phpVersion;
    public string $phpRunnerVersion;
    public string $eventLoop;
    public string $startFile;
    public int $totalMemory;
    public int $workersCount;
    public int $processesCount;

    /**
     * @param list<WorkerStatus> $workers
     * @param list<WorkerProcessStatus> $processes
     */
    public function __construct(
        public int $pid,
        public string $user,
        public int $memory,
        public \DateTimeImmutable|null $startedAt,
        public int $status,
        public array $workers,
        public array $processes = [],
    ) {
        $eventLoop = (new DriverFactory())->create();
        $eventLoopName = (new \ReflectionObject($eventLoop))->getShortName();

        $this->isRunning = $this->status === MasterProcess::STATUS_RUNNING;
        $this->phpVersion = PHP_VERSION;
        $this->phpRunnerVersion = PhpRunner::VERSION;
        $this->eventLoop = $eventLoopName;
        $this->startFile = Functions::getStartFile();
        $totalMemory = $this->memory;
        foreach ($processes as $process) {
            $totalMemory += $process->memory;
        }
        $this->totalMemory = $totalMemory;
        $this->workersCount = \count($this->workers);
        $this->processesCount = \count($this->processes);
    }
}
