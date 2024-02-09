<?php

declare(strict_types=1);

use Keboola\DbWriter\TestsFunctional\DatadirTest;

return function (DatadirTest $test): void {
    $sql = <<<SQL
CREATE TABLE `simple` (
    `id` INTEGER,
    `name` VARCHAR(255),
    `glasses` VARCHAR(20)
);
SQL;

    $test->connection->exec($sql);

    $sql = <<<SQL
INSERT INTO `simple` (`id`, `name`, `glasses`) VALUES
    (1, 'Johna', 'yes'),
    (2, 'Jane', 'no'),
    (3, 'Peter', 'yes'),
    (4, 'Paul', 'no');
SQL;

    $test->connection->exec($sql);
};
