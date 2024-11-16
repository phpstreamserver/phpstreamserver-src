<?php

declare(strict_types=1);

namespace Luzrain\PHPStreamServer\BundledPlugin\Supervisor;

use Amp\DeferredFuture;
use Amp\Future;
use Luzrain\PHPStreamServer\BundledPlugin\Supervisor\Message\ProcessHeartbeatEvent;
use Luzrain\PHPStreamServer\BundledPlugin\Supervisor\Internal\ReloadStrategyStack;
use Luzrain\PHPStreamServer\BundledPlugin\Supervisor\Message\ProcessSpawnedEvent;
use Luzrain\PHPStreamServer\BundledPlugin\Supervisor\ReloadStrategy\ReloadStrategyInterface;
use Luzrain\PHPStreamServer\Exception\UserChangeException;
use Luzrain\PHPStreamServer\Internal\Container;
use Luzrain\PHPStreamServer\Internal\ErrorHandler;
use Luzrain\PHPStreamServer\Internal\MessageBus\SocketFileMessageBus;
use Luzrain\PHPStreamServer\LoggerInterface;
use Luzrain\PHPStreamServer\MessageBus\Message\CompositeMessage;
use Luzrain\PHPStreamServer\MessageBus\MessageBusInterface;
use Luzrain\PHPStreamServer\MessageBus\MessageInterface;
use Luzrain\PHPStreamServer\Plugin;
use Luzrain\PHPStreamServer\Process;
use Luzrain\PHPStreamServer\ProcessUserChange;
use Luzrain\PHPStreamServer\Server;
use Luzrain\PHPStreamServer\Status;
use Psr\Container\ContainerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\DriverFactory;
use function Luzrain\PHPStreamServer\Internal\getCurrentGroup;
use function Luzrain\PHPStreamServer\Internal\getCurrentUser;

class WorkerProcess implements Process, MessageBusInterface, ContainerInterface
{
    final public const HEARTBEAT_PERIOD = 2;
    final public const RELOAD_EXIT_CODE = 100;
    private const GC_PERIOD = 180;

    use ProcessUserChange;

    private Status $status = Status::SHUTDOWN;
    private int $exitCode = 0;
    public readonly int $id;
    public readonly int $pid;
    protected readonly Container $container;
    public readonly LoggerInterface $logger;
    private readonly SocketFileMessageBus $messageBus;
    private DeferredFuture|null $startingFuture;
    private readonly ReloadStrategyStack $reloadStrategyStack;
    protected readonly \Closure $reloadStrategyTrigger;

    /**
     * @param null|\Closure(self):void $onStart
     * @param null|\Closure(self):void $onStop
     * @param null|\Closure(self):void $onReload
     * @param array<ReloadStrategyInterface> $reloadStrategies
     */
    public function __construct(
        public string $name = 'none',
        public readonly int $count = 1,
        public readonly bool $reloadable = true,
        private string|null $user = null,
        private string|null $group = null,
        private \Closure|null $onStart = null,
        private readonly \Closure|null $onStop = null,
        private readonly \Closure|null $onReload = null,
        private array $reloadStrategies = [],
    ) {
        static $nextId = 0;
        $this->id = ++$nextId;
    }

    /**
     * @internal
     */
    final public function run(Container $workerContainer): int
    {
        // some command line SAPIs (e.g. phpdbg) don't have that function
        if (\function_exists('cli_set_process_title')) {
            \cli_set_process_title(\sprintf('%s: worker process  %s', Server::NAME, $this->name));
        }

        EventLoop::setDriver((new DriverFactory())->create());

        $this->status = Status::STARTING;
        $this->pid = \posix_getpid();
        $this->container = $workerContainer;
        $this->logger = $workerContainer->get('logger')->withChannel('worker');
        $this->messageBus = $workerContainer->get('bus');

        ErrorHandler::register($this->logger);
        EventLoop::setErrorHandler(function (\Throwable $exception) {
            ErrorHandler::handleException($exception);
            $this->reloadStrategyStack->emitEvent($exception);
        });

        try {
            $this->setUserAndGroup($this->user, $this->group);
        } catch (UserChangeException $e) {
            $this->logger->warning($e->getMessage(), [(new \ReflectionObject($this))->getShortName() => $this->name]);
        }

        EventLoop::onSignal(SIGINT, static fn() => null);
        EventLoop::onSignal(SIGTERM, fn() => $this->stop());
        EventLoop::onSignal(SIGUSR1, fn() => $this->reload());

        // Force run garbage collection periodically
        EventLoop::repeat(self::GC_PERIOD, static function (): void {
            \gc_collect_cycles();
            \gc_mem_caches();
        });

        $this->reloadStrategyStack = new ReloadStrategyStack($this->reload(...), $this->reloadStrategies);
        $this->reloadStrategyTrigger = \Closure::fromCallable($this->reloadStrategyStack);
        unset($this->reloadStrategies);

        $heartbeatEvent = function (): ProcessHeartbeatEvent {
            return new ProcessHeartbeatEvent(
                pid: $this->pid,
                memory: \memory_get_usage(),
                time: \hrtime(true),
            );
        };

        $this->startingFuture = new DeferredFuture();

        EventLoop::repeat(self::HEARTBEAT_PERIOD, function () use ($heartbeatEvent) {
            $this->messageBus->dispatch($heartbeatEvent());
        });

        EventLoop::queue(function () use ($heartbeatEvent): void {
            $this->messageBus->dispatch(new CompositeMessage([
                new ProcessSpawnedEvent(
                    workerId: $this->id,
                    pid: $this->pid,
                    user: $this->getUser(),
                    name: $this->name,
                    reloadable: $this->reloadable,
                    startedAt: new \DateTimeImmutable('now'),
                ),
                $heartbeatEvent(),
            ]))->await();

            if ($this->onStart !== null) {
                EventLoop::queue(function () {
                    ($this->onStart)($this);
                });
            }
            $this->status = Status::RUNNING;
            $this->startingFuture->complete();
            $this->startingFuture = null;
        });

        EventLoop::run();

        return $this->exitCode;
    }

    /**
     * @return list<class-string<Plugin>>
     */
    static public function handleBy(): array
    {
        return [SupervisorPlugin::class];
    }

    final public function getUser(): string
    {
        return $this->user ?? getCurrentUser();
    }

    final public function getGroup(): string
    {
        return $this->group ?? getCurrentGroup();
    }

    /**
     * @template T
     * @param MessageInterface<T> $message
     * @return Future<T>
     */
    public function dispatch(MessageInterface $message): Future
    {
        return $this->messageBus->dispatch($message);
    }


    final public function get(string $id): mixed
    {
        return $this->container->get($id);
    }

    final public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    public function stop(int $code = 0): void
    {
        if ($this->status !== Status::STARTING && $this->status !== Status::RUNNING) {
            return;
        }

        $this->status = Status::STOPPING;
        $this->exitCode = $code;

        EventLoop::defer(function (): void {
            $this->startingFuture?->getFuture()->await();
            $this->messageBus->stop()->await();
            if ($this->onStop !== null) {
                ($this->onStop)($this);
            }
            EventLoop::getDriver()->stop();
        });
    }

    public function reload(): void
    {
        if (!$this->reloadable) {
            return;
        }

        if ($this->status !== Status::STARTING && $this->status !== Status::RUNNING) {
            return;
        }

        $this->status = Status::STOPPING;
        $this->exitCode = self::RELOAD_EXIT_CODE;

        EventLoop::defer(function (): void {
            $this->startingFuture?->getFuture()->await();
            $this->messageBus->stop()->await();
            if ($this->onReload !== null) {
                ($this->onReload)($this);
            }
            EventLoop::getDriver()->stop();
        });
    }

    public function addReloadStrategy(ReloadStrategyInterface ...$reloadStrategies): void
    {
        $this->reloadStrategyStack->addReloadStrategy(...$reloadStrategies);
    }
}
