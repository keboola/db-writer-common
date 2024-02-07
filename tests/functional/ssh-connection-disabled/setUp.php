<?php

declare(strict_types=1);

use Keboola\DbWriter\TestsFunctional\DatadirTest;

return function (DatadirTest $test): void {
    putenv('SSH_PUBLIC_KEY=' . file_get_contents('/root/.ssh/id_rsa.pub'));
    putenv('SSH_PRIVATE_KEY=' . file_get_contents('/root/.ssh/id_rsa'));
};
