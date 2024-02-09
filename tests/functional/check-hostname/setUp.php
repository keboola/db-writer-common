<?php

declare(strict_types=1);

use Keboola\DbWriter\TestsFunctional\DatadirTest;

return function (DatadirTest $test): void {
    $sql = <<<SQL
CREATE TABLE `simple` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `value` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

    $test->connection->exec($sql);
};
