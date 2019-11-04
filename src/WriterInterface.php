<?php

declare(strict_types=1);

namespace Keboola\DbWriter;

use Keboola\Csv\CsvFile;

interface WriterInterface
{
    /**
     * @return mixed
     */
    public function getConnection();

    /**
     * @param array $dbParams
     * @return mixed
     */
    public function createConnection(array $dbParams);
    public function write(CsvFile $csv, array $table): void;
    public function drop(string $tableName): void;
    public function create(array $table): void;
    public function upsert(array $table, string $targetTable): void;
    public function tableExists(string $tableName): bool;
    public function generateTmpName(string $tableName): string;
    public function showTables(string $dbName): array;
    public function getTableInfo(string $tableName): array;
    public static function getAllowedTypes(): array;
    public function validateTable(array $tableConfig): void;
}
