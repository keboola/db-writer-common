<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 25/05/15
 * Time: 14:52
 */

namespace Keboola\DbWriter;

interface WriterInterface
{
    /** @return \PDO */
    function getConnection();
    function createConnection($dbParams);
    function write($sourceFilename, $outputTableName, $table);
    function writeAsync($tableId, $outputTableName);
    function isTableValid(array $table, $ignoreExport = false);
    function drop($tableName);
    function create(array $table);
    static function getAllowedTypes();
    function isAsync();
}
