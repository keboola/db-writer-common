<?php

declare(strict_types=1);

namespace Keboola\DbWriter\TestsTraits;

use Symfony\Component\Process\Process;

trait CloseSshTunnelsTrait
{
    protected function closeSshTunnels(): void
    {
        # Close SSH tunnel if created
        $process = new Process(['sh', '-c', 'pgrep ssh | xargs -r kill']);
        $process->mustRun();
    }
}
