<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Writer;

use Keboola\DbWriterAdapter\ValueObject\TableDescriptions;
use Keboola\DbWriterConfig\Configuration\ValueObject\ExportConfig;

class TableDescriptionsReader
{
    /**
     * Reads native Storage descriptions (`description`, `schema[].description`) from the input table manifest.
     * Column descriptions are keyed by the destination column name. A missing or unreadable manifest
     * yields an empty result.
     */
    public function read(ExportConfig $exportConfig): TableDescriptions
    {
        $manifest = $this->readManifest($exportConfig->getTableFilePath() . '.manifest');
        if ($manifest === null) {
            return new TableDescriptions(null, []);
        }

        $tableDescription = $this->nonEmptyString($manifest['description'] ?? null);

        $columnDescriptions = [];
        $schema = $manifest['schema'] ?? null;
        if (is_array($schema)) {
            foreach ($schema as $column) {
                if (!is_array($column)) {
                    continue;
                }
                $name = $column['name'] ?? null;
                $description = $this->nonEmptyString($column['description'] ?? null);
                if (!is_string($name) || $description === null) {
                    continue;
                }
                foreach ($exportConfig->getItems() as $item) {
                    if ($item->getName() === $name && $item->getType() !== 'ignore') {
                        $columnDescriptions[$item->getDbName()] = $description;
                    }
                }
            }
        }

        return new TableDescriptions($tableDescription, $columnDescriptions);
    }

    /**
     * @return array<mixed>|null
     */
    private function readManifest(string $manifestPath): ?array
    {
        if (!is_file($manifestPath)) {
            return null;
        }
        $content = @file_get_contents($manifestPath);
        if ($content === false) {
            return null;
        }
        $manifest = json_decode($content, true);
        return is_array($manifest) ? $manifest : null;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
