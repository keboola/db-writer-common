<?php

declare(strict_types=1);

namespace Keboola\DbWriter;

use Keboola\Component\Config\BaseConfig;
use Keboola\DbWriter\Exception\ApplicationException;
use Keboola\DbWriter\Exception\InvalidDatabaseHostException;
use Psr\Log\LoggerInterface;

readonly class Validator
{
    public function __construct(public LoggerInterface $logger)
    {
    }

    /**
     * @throws InvalidDatabaseHostException
     */
    public function validateDatabaseHost(BaseConfig $config): void
    {
        if (!isset($config->getImageParameters()['approvedHostnames'])) {
            return;
        }
        $approvedHostnames = $config->getImageParameters()['approvedHostnames'];
        $db = $config->getParameters()['db'];
        $validHostname = array_filter($approvedHostnames, function ($v) use ($db) {
            return $v['host'] === $db['host'] && $v['port'] === $db['port'];
        });

        if (count($validHostname) === 0) {
            throw new InvalidDatabaseHostException(
                sprintf(
                    'Hostname "%s" with port "%s" is not approved.',
                    $db['host'],
                    $db['port'],
                ),
            );
        }
    }

    /**
     * @throws ApplicationException
     */
    public function validateTableItems(string $tablePath, array $items): array
    {
        $manifestPath = $tablePath . '.manifest';
        if (!file_exists($manifestPath)) {
            throw new ApplicationException(sprintf('Manifest "%s" not found.', $manifestPath));
        }

        $manifest = @json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            throw new ApplicationException(sprintf('Manifest "%s" is not valid JSON.', $manifestPath));
        }
        if (!isset($manifest['columns'])) {
            throw new ApplicationException(sprintf('Manifest "%s" is missing "columns" key.', $manifestPath));
        }

        /** @var iterable $csvHeader */
        $csvHeader = $manifest['columns'];
        $reordered = [];
        foreach ($csvHeader as $csvCol) {
            foreach ($items as $item) {
                if ($csvCol === $item['name']) {
                    $reordered[] = $item;
                }
            }
        }

        return $reordered;
    }
}
