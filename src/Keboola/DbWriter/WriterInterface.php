<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 25/05/15
 * Time: 14:52
 */

namespace Keboola\DbWriter;

use Keboola\Csv\CsvFile;

interface WriterInterface
{
    /** @return \PDO */
    public function getConnection();
    public function createConnection($dbParams);
    public function write(CsvFile $csv, array $table);
    public function drop($tableName);
    public function create(array $table);
    public function upsert(array $table, $targetTable);
    public function tableExists($tableName);
    public function generateTmpName($tableName);
    public function showTables($dbName);
    public function getTableInfo($tableName);
    public function isTableValid(array $table);
    public static function getAllowedTypes();
    public function isAsync();

}
