<?php

declare(strict_types=1);

namespace Keboola\DbWriter\TestsTraits;

use Keboola\DbWriterAdapter\PDO\PdoConnection;

trait DropAllTablesTrait
{
    public function dropAllTables(PdoConnection $connection): void
    {
        $sql = <<<END
          -- DROP TABLES --
          SET FOREIGN_KEY_CHECKS = 0; 
          SET @tables = NULL;
          SET GROUP_CONCAT_MAX_LEN=32768;
        
          SELECT GROUP_CONCAT('`', table_schema, '`.`', table_name, '`') INTO @tables
          FROM   information_schema.tables 
          WHERE  TABLE_SCHEMA NOT IN ("performance_schema", "mysql", "information_schema", "sys");
          SELECT IFNULL(@tables, '') INTO @tables;
        
          SET        @tables = CONCAT('DROP TABLE IF EXISTS ', @tables);
          PREPARE    stmt FROM @tables;
          EXECUTE    stmt;
          DEALLOCATE PREPARE stmt;
          SET        FOREIGN_KEY_CHECKS = 1;
          
          -- DROP VIEWS --
          SET @views = NULL;
          SELECT GROUP_CONCAT(table_schema, '.', table_name) INTO @views 
          FROM information_schema.views 
          WHERE  TABLE_SCHEMA NOT IN ("performance_schema", "mysql", "information_schema", "sys"); 

          SET @views = IFNULL(CONCAT('DROP VIEW ', @views), 'SELECT "No Views"');
          PREPARE stmt FROM @views;
          EXECUTE stmt;
          DEALLOCATE PREPARE stmt;
        END;

        $connection->exec($sql);
    }
}
