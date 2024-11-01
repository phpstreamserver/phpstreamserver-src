<?php

declare(strict_types=1);

namespace Luzrain\PHPStreamServer\BundledPlugin\Supervisor;

use Luzrain\PHPStreamServer\BundledPlugin\Supervisor\Event\ProcessDetachedEvent;

final class ExternalProcess extends WorkerProcess
{
    public function __construct(
        string $name = 'none',
        int $count = 1,
        bool $reloadable = true,
        string|null $user = null,
        string|null $group = null,
        private readonly string $command = '',
    ) {
        parent::__construct(name: $name, count: $count, reloadable: $reloadable, user: $user, group: $group, onStart: $this->onStart(...));
    }

    private function onStart(): void
    {
        $this->dispatch(new ProcessDetachedEvent($this->pid))->await();

        if ($this->commandValidate($this->command)) {
            \register_shutdown_function($this->exec(...), ...$this->convertCommandToPcntl($this->command));
            $this->stop();
        } else {
            $this->stop(1);
        }
    }

    private function commandValidate(string $command): bool
    {
        if ($command === '') {
            $this->logger->critical('External process call error: command can not be empty', ['comand' => $this->command]);

            return false;
        }

        // Check if command contains logic operators such as && and ||
        if(\preg_match('/(\'[^\']*\'|"[^"]*")(*SKIP)(*FAIL)|&&|\|\|/', $this->command) === 1) {
            $this->logger->critical(\sprintf(
                'External process call error: logical operators not supported, use shell with -c option e.g. "/bin/sh -c "%s"',
                $this->command,
            ), ['comand' => $this->command]);

            return false;
        }

        return true;
    }

    /**
     * Prepare command for pcntl_exec acceptable format
     *
     * @param non-empty-string $command
     * @return array{0: string, 1: list<string>}
     */
    private function convertCommandToPcntl(string $command): array
    {
        \preg_match_all('/\'[^\']*\'|"[^"]*"|\S+/', $command, $matches);
        $parts = \array_map(static fn (string $part): string => \trim($part, '"\''), $matches[0]);
        $binary = \array_shift($parts);
        $args = $parts;

        if (!\str_starts_with($binary, '/') && \is_string($absoluteBinaryPath = \shell_exec("command -v $binary"))) {
            $binary = \trim($absoluteBinaryPath);
        }

        return [$binary, $args];
    }

    /**
     * Give control to an external program
     *
     * @param string $path path to a binary executable or a script
     * @param array $args array of argument strings passed to the program
     * @see https://www.php.net/manual/en/function.pcntl-exec.php
     */
    private function exec(string $path, array $args): never
    {
        $envVars = [...\getenv(), ...$_ENV];

        \set_error_handler(function (int $code): void {
            $this->logger->critical('External process call error: ' . \posix_strerror($code), ['comand' => $this->command]);
        });

        \pcntl_exec($path, $args, $envVars);

        exit(1);
    }
}
