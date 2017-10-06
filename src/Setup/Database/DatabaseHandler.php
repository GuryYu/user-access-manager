<?php
/**
 * DatabaseHandler.php
 *
 * The DatabaseHandler class file.
 *
 * PHP versions 5
 *
 * @author    Alexander Schneider <alexanderschneider85@gmail.com>
 * @copyright 2008-2017 Alexander Schneider
 * @license   http://www.gnu.org/licenses/gpl-2.0.html  GNU General Public License, version 2
 * @version   SVN: $id$
 * @link      http://wordpress.org/extend/plugins/user-access-manager/
 */
namespace UserAccessManager\Setup\Database;

use UserAccessManager\Database\Database;
use UserAccessManager\Setup\Update\UpdateFactory;
use UserAccessManager\Setup\Update\UpdateInterface;
use UserAccessManager\UserAccessManager;
use UserAccessManager\Wrapper\Wordpress;

/**
 * Class DatabaseHandler
 *
 * @package UserAccessManager\Setup\Database
 */
class DatabaseHandler
{
    /**
     * @var Wordpress
     */
    private $wordpress;

    /**
     * @var Database
     */
    private $database;

    /**
     * @var DatabaseObjectFactory
     */
    private $databaseObjectFactory;

    /**
     * @var UpdateFactory
     */
    private $updateFactory;

    /**
     * DatabaseHandler constructor.
     *
     * @param Wordpress             $wordpress
     * @param Database              $database
     * @param DatabaseObjectFactory $databaseObjectFactory
     * @param UpdateFactory         $updateFactory
     */
    public function __construct(
        Wordpress $wordpress,
        Database $database,
        DatabaseObjectFactory $databaseObjectFactory,
        UpdateFactory $updateFactory
    ) {
        $this->wordpress = $wordpress;
        $this->database = $database;
        $this->databaseObjectFactory = $databaseObjectFactory;
        $this->updateFactory = $updateFactory;
    }

    /**
     * Checks if the table exists.
     *
     * @param string $table
     *
     * @return bool
     */
    private function tableExists($table)
    {
        $dbTable = $this->database->getVariable("SHOW TABLES  LIKE '{$table}'");

        return ($table === $dbTable);
    }

    /**
     * Adds a table.
     *
     * @param Table $table
     */
    private function addTable(Table $table)
    {
        if ($this->tableExists($table->getName()) === false) {
            $this->database->dbDelta((string)$table);
        }
    }

    /**
     * Returns all tables.
     *
     * @return Table[]
     */
    private function getTables()
    {
        $charsetCollate = $this->database->getCharset();
        $tables = [];

        $tables[] = $this->databaseObjectFactory->createTable(
            $this->database->getUserGroupTable(),
            $charsetCollate,
            [
                $this->databaseObjectFactory->createColumn('ID', 'INT(11)', false, null, true, true),
                $this->databaseObjectFactory->createColumn('groupname', 'TINYTEXT'),
                $this->databaseObjectFactory->createColumn('groupdesc', 'TEXT'),
                $this->databaseObjectFactory->createColumn('read_access', 'TINYTEXT'),
                $this->databaseObjectFactory->createColumn('write_access', 'TINYTEXT'),
                $this->databaseObjectFactory->createColumn('ip_range', 'MEDIUMTEXT', true)
            ]
        );

        $tables[] = $this->databaseObjectFactory->createTable(
            $this->database->getUserGroupToObjectTable(),
            $charsetCollate,
            [
                $this->databaseObjectFactory->createColumn('object_id', 'VARCHAR(32)', false, null, true),
                $this->databaseObjectFactory->createColumn('general_object_type', 'VARCHAR(64)'),
                $this->databaseObjectFactory->createColumn('object_type', 'VARCHAR(32)', false, null, true),
                $this->databaseObjectFactory->createColumn('group_id', 'VARCHAR(32)', false, null, true),
                $this->databaseObjectFactory->createColumn('group_type', 'VARCHAR(32)', false, null, true),
                $this->databaseObjectFactory->createColumn('from_date', 'DATETIME', true),
                $this->databaseObjectFactory->createColumn('to_date', 'DATETIME', true)
            ]
        );

        return $tables;
    }

    /**
     * Adds the tables to the database.
     */
    public function install()
    {
        foreach ($this->getTables() as $table) {
            $this->addTable($table);
        }

        $this->wordpress->addOption('uam_db_version', UserAccessManager::DB_VERSION);
    }

    /**
     * Returns the existing columns for a table.
     *
     * @param Table $table
     *
     * @return Column[]
     */
    private function getExistingColumns(Table $table)
    {
        $query = "SHOW COLUMNS FROM `{$table->getName()}`;";
        $existingRawColumns = $this->database->getResults($query);
        $existingColumns = [];

        foreach ($existingRawColumns as $existingRawColumn) {
            $existingColumns[$existingRawColumn->Field] = $this->databaseObjectFactory->createColumn(
                $existingRawColumn->Field,
                strtoupper($existingRawColumn->Type),
                $existingRawColumn->Null === 'YES',
                $existingRawColumn->Default,
                $existingRawColumn->Key === 'PRI',
                $existingRawColumn->Extra === 'auto_increment'
            );
        }

        return $existingColumns;
    }

    /**
     * Adds a new column.
     *
     * @param Table  $table
     * @param Column $column
     */
    private function addColumn(Table $table, Column $column)
    {
        $this->database->query("ALTER TABLE `{$table->getName()}` ADD $column;");
    }

    /**
     * Modify an existing column.
     *
     * @param Table  $table
     * @param Column $column
     */
    private function modifyColumn(Table $table, Column $column)
    {
        $this->database->query("ALTER TABLE `{$table->getName()}` MODIFY $column;");
    }

    /**
     * Drops an existing column.
     *
     * @param Table  $table
     * @param Column $column
     */
    private function dropColumn(Table $table, Column $column)
    {
        $this->database->query("ALTER TABLE `{$table->getName()}` DROP `{$column->getName()}`;");
    }

    /**
     * Repairs a corrupt database.
     */
    public function repairDatabase()
    {
        foreach ($this->getTables() as $table) {
            if ($this->tableExists($table->getName()) === false) {
                $this->addTable($table);
                continue;
            }

            $existingColumns = $this->getExistingColumns($table);

            foreach ($table->getColumns() as $column) {
                if (isset($existingColumns[$column->getName()]) === false) {
                    $this->addColumn($table, $column);
                    continue;
                }

                $existingColumn = $existingColumns[$column->getName()];
                unset($existingColumns[$column->getName()]);

                if ((string)$column !== (string)$existingColumn) {
                    $this->modifyColumn($table, $column);
                    continue;
                }
            }

            foreach ($existingColumns as $existingColumn) {
                $this->dropColumn($table, $existingColumn);
            }
        }
    }

    /**
     * Checks if a database update is necessary.
     *
     * @return bool
     */
    public function isDatabaseUpdateNecessary()
    {
        if ($this->wordpress->isSuperAdmin() === true) {
            foreach ($this->wordpress->getSites() as $site) {
                $table = $this->database->getBlogPrefix($site->blog_id).'options';
                $select = "SELECT option_value FROM {$table} WHERE option_name = '%s' LIMIT 1";
                $select = $this->database->prepare($select, 'uam_db_version');
                $currentDbVersion = $this->database->getVariable($select);

                if ($currentDbVersion !== null
                    && version_compare($currentDbVersion, UserAccessManager::DB_VERSION, '<') === true
                ) {
                    return true;
                }
            }
        }

        $currentDbVersion = $this->wordpress->getOption('uam_db_version');
        return version_compare($currentDbVersion, UserAccessManager::DB_VERSION, '<');
    }

    /**
     * Creates a database backup.
     *
     * @return bool
     */
    public function backupDatabase()
    {
        $currentDbVersion = $this->wordpress->getOption('uam_db_version');

        if (empty($currentDbVersion) === true
            || version_compare($currentDbVersion, '1.2', '<') === true
        ) {
            return false;
        }

        $tables = [
            $this->database->getUserGroupTable(),
            $this->database->getUserGroupToObjectTable()
        ];

        $currentDbVersion = str_replace('.', '-', $currentDbVersion);
        $success = true;

        foreach ($tables as $table) {
            $createQuery = "CREATE TABLE `{$table}_{$currentDbVersion}` LIKE `{$table}`";
            $success = $success && ($this->database->query($createQuery) !== false);
            $insertQuery = "INSERT `{$table}_{$currentDbVersion}` SELECT * FROM `{$table}`";
            $success = $success && ($this->database->query($insertQuery) !== false);
        }

        return $success;
    }

    /**
     * Returns the version for which a backup was created.
     *
     * @return array
     */
    public function getBackups()
    {
        $versions = [];
        $tables = (array)$this->database->getColumn(
            "SHOW TABLES LIKE '{$this->database->getPrefix()}uam_%'"
        );

        foreach ($tables as $table) {
            if (preg_match('/.*\_([0-9\-]+)/i', $table, $matches) === 1) {
                $version = str_replace('-', '.', $matches[1]);
                $versions[$version] = $version;
            }
        }

        return $versions;
    }

    /**
     * Returns the backup tables for the given version.
     *
     * @param string $version
     *
     * @return array
     */
    private function getBackupTables($version)
    {
        $backupTables = [];
        $tables = [
            $this->database->getUserGroupTable(),
            $this->database->getUserGroupToObjectTable()
        ];

        $versionForDb = str_replace('.', '-', $version);

        foreach ($tables as $table) {
            $backupTable = (string)$this->database->getVariable(
                "SHOW TABLES LIKE '{$table}_{$versionForDb}'"
            );

            if ($backupTable !== '') {
                $backupTables[$table] = $backupTable;
            }
        }

        return $backupTables;
    }

    /**
     * Reverts the database to the given version.
     *
     * @param string $version
     *
     * @return bool
     */
    public function revertDatabase($version)
    {
        $success = true;
        $tables = $this->getBackupTables($version);

        foreach ($tables as $table => $backupTable) {
            $dropQuery = "DROP TABLE IF EXISTS `{$table}`";
            $success = $success && ($this->database->query($dropQuery) !== false);
            $renameQuery = "RENAME TABLE `{$backupTable}` TO `{$table}`";
            $success = $success && ($this->database->query($renameQuery) !== false);
        }

        if ($success === true) {
            $this->wordpress->updateOption('uam_db_version', $version);
        }

        return $success;
    }

    /**
     * Deletes the given database backup.
     *
     * @param string $version
     *
     * @return bool
     */
    public function deleteBackup($version)
    {
        $success = true;
        $tables = $this->getBackupTables($version);

        foreach ($tables as $table => $backupTable) {
            $dropQuery = "DROP TABLE IF EXISTS `{$backupTable}`";
            $success = $success && ($this->database->query($dropQuery) !== false);
        }

        return $success;
    }

    /**
     * Returns the ordered updates.
     *
     * @return UpdateInterface[]
     */
    private function getOrderedDatabaseUpdates()
    {
        $rawUpdates = $this->updateFactory->getDatabaseUpdates();
        $updates = [];

        foreach ($rawUpdates as $rawUpdate) {
            $updates[$rawUpdate->getVersion()] = $rawUpdate;
        }

        uksort($updates, 'version_compare');
        return $updates;
    }

    /**
     * Updates the database.
     *
     * @return bool
     */
    public function updateDatabase()
    {
        $currentDbVersion = $this->wordpress->getOption('uam_db_version');

        if (empty($currentDbVersion) === true) {
            return false;
        }

        $success = true;

        if (version_compare($currentDbVersion, UserAccessManager::DB_VERSION, '<') === true) {
            foreach ($this->getOrderedDatabaseUpdates() as $orderedUpdate) {
                if (version_compare($currentDbVersion, $orderedUpdate->getVersion(), '<') === true) {
                    $success = $success && $orderedUpdate->update();
                }
            }

            if ($success === true) {
                $this->wordpress->updateOption('uam_db_version', UserAccessManager::DB_VERSION);
            }
        }

        return $success;
    }

    /**
     * Removes the tables.
     */
    public function removeTables()
    {
        foreach ($this->getTables() as $table) {
            $dropQuery = "DROP TABLE IF EXISTS `{$table->getName()}`";
            $this->database->query($dropQuery);
        }
    }
}