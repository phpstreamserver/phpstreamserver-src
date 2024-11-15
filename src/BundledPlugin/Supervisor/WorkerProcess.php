<?php

declare(strict_types=1);

namespace Luzrain\PHPStreamServer\BundledPlugin\Supervisor;

use Luzrain\PHPStreamServer\BundledPlugin\Supervisor\Message\ProcessSetOptionsEvent;
use Luzrain\PHPStreamServer\BundledPlugin\Supervisor\Internal\ReloadStrategyStack;
use Luzrain\PHPStreamServer\BundledPlugin\Supervisor\ReloadStrategy\ReloadStrategyInterface;
use Luzrain\PHPStreamServer\Internal\ErrorHandler;
use Luzrain\PHPStreamServer\Process;
use Revolt\EventLoop;

class WorkerProcess extends Process
{
    final public const RELOAD_EXIT_CODE = 100;
    private const GC_PERIOD = 180;

    protected readonly \Closure $reloadStrategyTrigger;
    private readonly ReloadStrategyStack $reloadStrategyStack;
    private bool $isReloading = false;

    /**
     * @param null|\Closure(self):void $onStart
     * @param null|\Closure(self):void $onStop
     * @param null|\Closure(self):void $onReload
     * @param array<ReloadStrategyInterface> $reloadStrategies
     */
    public function __construct(
        string $name = 'none',
        public readonly int $count = 1,
        public readonly bool $reloadable = true,
        string|null $user = null,
        string|null $group = null,
        \Closure|null $onStart = null,
        private readonly \Closure|null $onStop = null,
        private readonly \Closure|null $onReload = null,
        private array $reloadStrategies = [],
    ) {
        parent::__construct(name: $name, user: $user, group: $group, onStart: $onStart, onStop: $this->onStop(...));
    }

    static public function handleBy(): array
    {
        return [SupervisorPlugin::class];
    }

    private function onStop(self $process): void
    {
        if ($this->isReloading && $this->onReload !== null) {
            ($this->onReload)($process);
        } elseif (!$this->isReloading && $this->onStop !== null) {
            ($this->onStop)($process);
        }
    }

    protected function start(): void
    {
        EventLoop::onSignal(SIGUSR1, fn() => $this->reload());

        // Force run garbage collection periodically
        EventLoop::repeat(self::GC_PERIOD, static function (): void {
            \gc_collect_cycles();
            \gc_mem_caches();
        });

        $this->reloadStrategyStack = new ReloadStrategyStack($this->reload(...), $this->reloadStrategies);
        $this->reloadStrategyTrigger = \Closure::fromCallable($this->reloadStrategyStack);
        unset($this->reloadStrategies);

        EventLoop::setErrorHandler(function (\Throwable $exception) {
            ErrorHandler::handleException($exception);
            $this->reloadStrategyStack->emitEvent($exception);
        });

        EventLoop::queue(function (): void {
            $this->dispatch(new ProcessSetOptionsEvent(
                pid: $this->pid,
                reloadable: $this->reloadable,
            ));
        });
    }

    public function stop(int $code = 0): void
    {
        $this->isReloading = $this->reloadable && $code === self::RELOAD_EXIT_CODE;
        parent::stop($code);
    }

    public function reload(): void
    {
        if ($this->reloadable) {
            $this->stop(self::RELOAD_EXIT_CODE);
        }
    }

    public function addReloadStrategy(ReloadStrategyInterface ...$reloadStrategies): void
    {
        $this->reloadStrategyStack->addReloadStrategy(...$reloadStrategies);
    }
}
