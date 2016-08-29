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
    function write($sourceFilename, array $table);
    function isTableValid(array $table);
    function drop($tableName);
    function create(array $table);
    function upsert(array $table, $targetTable);
    static function getAllowedTypes();
    function isAsync();
}
