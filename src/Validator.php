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
            if (!array_key_exists('port', $v)) {
                return $v['host'] === $db['host'];
            }
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
    public function validateTableManifest(string $tablePath): void
    {
        $manifest = $this->getTableManifest($tablePath);

        if (!is_array($manifest)) {
            throw new ApplicationException(sprintf('Manifest "%s.manifest" is not valid JSON.', $tablePath));
        }
        if (!isset($manifest['columns'])) {
            throw new ApplicationException(sprintf('Manifest "%s.manifest" is missing "columns" key.', $tablePath));
        }
    }

    public function reorderItems(string $tablePath, array $items): array
    {
        $manifest = $this->getTableManifest($tablePath);
        if (!$manifest) {
            return $items;
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

    private function getTableManifest(string $tablePath): ?array
    {
        $manifestPath = $tablePath . '.manifest';
        if (!file_exists($manifestPath)) {
            throw new ApplicationException(sprintf('Manifest "%s" not found.', $manifestPath));
        }

        $manifest = @json_decode((string) file_get_contents($manifestPath), true);
        if (!$manifest) {
            return null;
        }

        return (array) $manifest;
    }
}
