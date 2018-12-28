<?php
/**
 * PitonCMS (https://github.com/PitonCMS)
 *
 * @link      https://github.com/PitonCMS
 * @copyright Copyright (c) 2015 - 2019 Wolfgang Moritz
 * @license   https://github.com/PitonCMS/ORM/blob/master/LICENSE (MIT License)
 */
namespace Piton\ORM;

use \PDO;
use \Exception;

/**
 * Piton Abstract Data Mapper Class
 *
 * All domain data mapper classes should extend this class.
 * SQL statements return DomainObjects.
 */
abstract class DataMapperAbstract
{
    // ------------------------------------------------------------------------
    // Define these properties in the child class
    // ------------------------------------------------------------------------

    /**
     * Table Name
     * @var String
     */
    protected $table;

    /**
     * Table Alias, if needed
     * @var String
     */
    protected $tableAlias;

    /**
     * Primary Key Column Name
     * Define if not 'id'
     * @var String
     */
    protected $primaryKey = 'id';

    /**
     * Updatable or Insertable Columns, not including the who columns
     * @var Array
     */
    protected $modifiableColumns = [];

    /**
     * Domain Object Class
     * @var String
     */
    protected $domainObjectClass = 'DomainObject';

    /**
     * Does this table have created_by, created_date, updated_by, and updated_date?
     * @var Boolean
     */
    protected $who = true;

    // ------------------------------------------------------------------------
    // Do not directly set properties below, these are set at runtime
    // ------------------------------------------------------------------------

    /**
     * Database Connection Object
     * @var Database Connection Object
     */
    private $dbh;

    /**
     * PDO Fetch Mode
     * @var PDO Fetch Mode Constant
     */
    protected $fetchMode = PDO::FETCH_CLASS;

    /**
     * Session User ID
     * @var Int
     */
    protected $sessionUserId;

    /**
     * Application Object
     * @var Application Object
     */
    protected $logger;

    /**
     * SQL Statement to Execute
     * @var String
     */
    protected $sql;

    /**
     * Bind Values
     * @var Array
     */
    protected $bindValues = [];

    /**
     * Statement Being Executed
     * @var Prepared Statement Object
     */
    protected $statement;

    /**
     * Construct
     *
     * Only PDO supported for now
     * @param object $dbConnection Database connection: PDO
     * @param array  $settings     Optional array of settings
     */
    public function __construct($dbConnection, array $settings = [])
    {
        if ($dbConnection instanceof PDO) {
            $this->dbh = $dbConnection;
        } else {
            throw new Exception("Invalid database connection provided, expected PDO", 1);
        }

        if (!empty($settings)) {
            $this->logger = (array_key_exists('logger', $settings)) ? $settings['logger'] : null;
            $this->sessionUserId = (array_key_exists('user_id', $settings)) ? $settings['user_id'] : null;
        }
    }

    /**
     * Create a new Domain Object
     *
     * Uses the $domainObjectClass defined in the child class
     * @return Object
     */
    public function make()
    {
        $fullyQualifedClassName = __NAMESPACE__ . '\\' . $this->domainObjectClass;

        return new $fullyQualifedClassName;
    }

    /**
     * Get one table row by the primary key ID
     *
     * @param $id, primary key ID
     * @return mixed, Domain Object if found, null otherwise
     */
    public function findById($id)
    {
        // Use default select statement and add where clause, unless other SQL has been supplied
        if (empty($this->sql)) {
            $this->makeSelect();
            $this->sql .= ' where ';
            $this->sql .= ($this->tableAlias) ?: $this->table;
            $this->sql .= '.' . $this->primaryKey . ' = ?';
        }

        $this->bindValues[] = $id;

        return $this->findRow();
    }

    /**
     * Find Single Record
     *
     * Use if the SQL is expecting one row
     * @return array
     */
    public function findRow()
    {
        // If no SQL was provided, return null
        if (!$this->sql) {
            $this->makeSelect();
        }

        // Execute the query & return
        $this->execute();

        return $this->statement->fetch();
    }

    /**
     * Get Table Rows
     *
     * Returns all table rows, or if a custom SQL is set then returns matching rows
     *
     * Returns an array of Domain Objects (one for each record)
     * @return Array
     */
    public function find()
    {
        // Use default select statement unless other SQL has been supplied
        if (!$this->sql) {
            $this->makeSelect();
        }

        // Execute the query
        $this->execute();

        return $this->statement->fetchAll();
    }

    /**
     * Count Found Rows
     *
     * Returns the total number of rows for the last query if SQL_CALC_FOUND_ROWS is included
     */
    public function foundRows()
    {
        return $this->dbh->query('select found_rows()')->fetch(PDO::FETCH_COLUMN);
    }

    /**
     * Save Domain Object (Public)
     *
     * Define in child class to add any manipulation before coreSave()
     * @param Domain Object
     * @return mixed, Domain Object on success, false otherwise
     */
    public function save(DomainObject $domainObject)
    {
        return $this->coreSave($domainObject);
    }

    /**
     * Update a Record (Public)
     *
     * Define in child class to add any manipulation before coreUpdate()
     * @param Domain Object
     * @return Domain Object
     */
    public function update(DomainObject $domainObject)
    {
        return $this->coreUpdate($domainObject);
    }

    /**
     * Insert a Record (Public)
     *
     * Define in child class to add any manipulation before coreInsert()
     * @param Domain Object
     * @return Domain Object
     */
    public function insert(DomainObject $domainObject)
    {
        return $this->coreInsert($domainObject);
    }

    /**
     * Delete a Record (Public)
     *
     * Define in child class to override behavior
     * @param Domain Object
     * @return Boolean
     */
    public function delete(DomainObject $domainObject)
    {
        return $this->coreDelete($domainObject);
    }

    /**
     * Current Date Time
     *
     * Returns datetime string in MySQL Format
     * @return string
     */
    public function now()
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Current Date
     *
     * Returns date string in MySQL Format
     * @return string
     */
    public function today()
    {
        return date('Y-m-d');
    }

    // ------------------------------------------------------------------------
    // Protected Methods
    // ------------------------------------------------------------------------

    /**
     * Save Domain Object (Protected)
     *
     * Inserts or updates Domain Object record
     * @param Domain Object
     * @return mixed, Domain Object on success, false otherwise
     */
    protected function coreSave(DomainObject $domainObject)
    {
        if (!empty($domainObject->{$this->primaryKey})) {
            return $this->update($domainObject);
        } else {
            return $this->insert($domainObject);
        }
    }

    /**
     * Update a Record (Protected)
     *
     * Updates a single record using the primarky key ID
     * @param Domain Object
     * @return Domain Object
     */
    protected function coreUpdate(DomainObject $domainObject)
    {
        // Make sure a primary key was set
        if (empty($domainObject->{$this->primaryKey})) {
            throw new Exception('A primary key id was not provided to update the record.');
        }

        // Get started
        $this->sql = 'update ' . $this->table . ' set ';

        // Use set object properties which match the list of updatable columns
        $hasBeenSet = 0;
        foreach ($this->modifiableColumns as $column) {
            if (isset($domainObject->$column)) {
                $this->sql .= $column . ' = ?, ';
                $this->bindValues[] = $domainObject->$column;
                $hasBeenSet++;
            }
        }

        // Is there anything to actually update?
        if ($hasBeenSet === 0) {
            // No, log and return
            if ($this->logger) {
                $this->logger->debug('Nothing to update');
            }

            return null;
        }

        // Remove last comma at end of SQL string
        $this->sql = rtrim($this->sql, ', ');

        // Set Who columns
        if ($this->who) {
            $this->sql .= ', updated_by = ?, updated_date = ? ';
            $this->bindValues[] = $this->sessionUserId;
            $this->bindValues[] = $this->now();
        }

        // Append where clause
        $this->sql .= ' where ' . $this->primaryKey . ' = ?;';
        $this->bindValues[] = $domainObject->{$this->primaryKey};

        // Execute
        $this->execute();

        return $domainObject;
    }

    /**
     * Insert a New Record (Protected)
     *
     * @param DomainObject
     * @param bool, Include IGNORE syntax
     * @return Domain Object
     */
    protected function coreInsert(DomainObject $domainObject, $ignore = false)
    {
        // Get started
        $this->sql = 'insert ';
        $this->sql .= ($ignore) ? 'ignore ' : '';
        $this->sql .= 'into ' . $this->table . ' (';

        // Insert values placeholder string
        $insertValues = ' ';

        $hasBeenSet = 0;
        foreach ($this->modifiableColumns as $column) {
            if (isset($domainObject->$column)) {
                $this->sql .= $column . ', ';
                $insertValues .= '?, ';
                $this->bindValues[] = $domainObject->$column;
                $hasBeenSet++;
            }
        }

        // Is there anything to actually insert?
        if ($hasBeenSet === 0) {
            // No, log and return
            if ($this->logger) {
                $this->logger->debug('Nothing to insert');
            }

            return null;
        }

        // Remove trailing commas
        $this->sql = rtrim($this->sql, ', ');
        $insertValues = rtrim($insertValues, ', ');

        // Set Who columns
        if ($this->who) {
            // Append statement
            $this->sql .= ', created_by, created_date, updated_by, updated_date';
            $insertValues .= ', ?, ?, ?, ?';

            // Add binds
            $this->bindValues[] = $this->sessionUserId;
            $this->bindValues[] = $this->now();
            $this->bindValues[] = $this->sessionUserId;
            $this->bindValues[] = $this->now();
        }

        // Close and concatenate strings
        $this->sql .= ') values (' . $insertValues . ');';

        // Execute and assign last insert ID to primary key and return
        $this->execute();
        $domainObject->{$this->primaryKey} = $this->dbh->lastInsertId();

        return $domainObject;
    }

    /**
     * Delete a Record (Protected)
     *
     * @param Domain Object
     * @return Boolean
     */
    protected function coreDelete(DomainObject $domainObject)
    {
        // Make sure the ID was set
        if (empty($domainObject->{$this->primaryKey})) {
            throw new Exception('A primary key id was not provided to delete this record.');
        }

        // Make SQL Statement
        $this->sql = 'delete from ' . $this->table . ' where ' . $this->primaryKey . ' = ?;';
        $this->bindValues[] = $domainObject->{$this->primaryKey};

        // Execute
        return $this->execute();
    }

    /**
     * Make Default Select
     *
     * Make select statement if $this->sql is not set
     * @param  bool $foundRows False. Set to true to get total found rows after query
     * @return none
     */
    protected function makeSelect($foundRows = false)
    {
        if (!isset($this->sql)) {
            $this->sql = 'select SQL_CALC_FOUND_ROWS ';
            $this->sql .= $foundRows ? 'SQL_CALC_FOUND_ROWS ' : '';
            $this->sql .= ($this->tableAlias) ?: $this->table;
            $this->sql .= '.* from ' . $this->table . ' ' . $this->tableAlias;
        }
    }

    /**
     * Clear Prior SQL Statement
     *
     * Resets $sql, $bindValues, and $fetchMode
     * Called after executing prior statement
     * @return void
     */
    protected function clear()
    {
        $this->sql = null;
        $this->bindValues = [];
        $this->fetchMode = PDO::FETCH_CLASS;
    }

    /**
     * Execute SQL
     *
     * Executes $this->sql string using $this->bindValues array
     * Returns true/false for DML, and query result array for selects
     * @return mixed
     */
    protected function execute()
    {
        // Log query and binds
        if ($this->logger) {
            $this->logger->debug('SQL Statement: ' . $this->sql);
            $this->logger->debug('SQL Binds: ' . print_r($this->bindValues, true));
        }

        // Prepare the query
        $this->statement = $this->dbh->prepare($this->sql);

        // Bind values
        foreach ($this->bindValues as $key => $value) {
            // Determine data type
            if (is_int($value)) {
                $paramType = PDO::PARAM_INT;
            } elseif ($value === '') {
                $value = null;
                $paramType = PDO::PARAM_NULL;
            } else {
                $paramType = PDO::PARAM_STR;
            }

            $this->statement->bindValue($key + 1, $value, $paramType);
        }

        // Execute the query
        if (false === $outcome = $this->statement->execute()) {
            // If false is returned there was a problem so log it
            if ($this->logger) {
                $this->logger->error('PDO Execute Returns False: ' . $this->sql);
                $this->logger->error('PDO SQL Binds: ' . print_r($this->bindValues, true));
            }

            return null;
        }

        // If a select statement was executed, set fetch mode
        if (stristr($this->sql, 'select')) {
            if ($this->fetchMode === PDO::FETCH_CLASS) {
                $this->statement->setFetchMode($this->fetchMode, __NAMESPACE__ . '\\' . $this->domainObjectClass);
            } else {
                $this->statement->setFetchMode($this->fetchMode);
            }
        }

        // Clear last query
        $this->clear();

        return $outcome;
    }

    /**
     * Define Table Definition
     *
     * Set table configurations
     * @param array $table Table definition
     * @return void
     */
    protected function define($table)
    {
        // Required, table name
        $this->table = $table['table'];

        // Optional
        if (isset($table['tableAlias'])) {
            $this->tableAlias = $table['tableAlias'];
        }

        // Optional, update to match table name
        if (isset($table['primaryKey'])) {
            $this->primaryKey = $table['primaryKey'];
        }

        // Required, array of updatable or insertable columns
        // Do not includ the who columns or primary key
        if (isset($table['modifiableColumns']) && is_array($table['modifiableColumns'])) {
            $modifiableColumns = $table['modifiableColumns'];
        } else {
            throw new Exception("modifiableColumns is required and must be an array of column names");
        }

        // Optional, name of custom value object class
        if (isset($table['domainObjectClass'])) {
            $this->domainObjectClass = $table['domainObjectClass'];
        }

        // Optional, set flag if table has created_by, created_date, updated_by, updated_date columns
        if (isset($table['who']) && ($table['who'] === true || $table['who'] === false)) {
            $this->who = $table['who'];
        } else {
            throw new Exception("The the Who column flag must be TRUE or FALSE");
        }
    }
}
