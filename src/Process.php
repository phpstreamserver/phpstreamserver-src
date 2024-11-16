<?php

declare(strict_types=1);

namespace Luzrain\PHPStreamServer;

use Luzrain\PHPStreamServer\Internal\Container;

interface Process
{
    public function run(Container $workerContainer): int;

    /**
     * @return list<class-string<Plugin>>
     */
    static public function handleBy(): array;
}
