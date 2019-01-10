<?php
/**
 * PitonCMS (https://github.com/PitonCMS)
 *
 * @link      https://github.com/PitonCMS/ORM
 * @copyright Copyright (c) 2015 - 2019 Wolfgang Moritz
 * @license   https://github.com/PitonCMS/ORM/blob/master/LICENSE (MIT License)
 */
namespace Piton\ORM;

use \PDO;
use \Exception;

/**
 * Piton Abstract Data Mapper Class
 *
 * All data mapper classes for tables should extend this class.
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
    protected $domainObjectClass = __NAMESPACE__ . '\DomainObject';

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
     * Now 'Y-m-d H:i:s'
     * @var String
     */
    protected $now;

    /**
     * Today 'Y-m-d'
     * @var String
     */
    protected $today;

    /**
     * Construct
     *
     * Only PDO supported for now
     * Optional settings:
     * - sessionUserId: Application session user ID to set in created by and updated by fields
     * - logger: Logging object
     * @param  object $dbConnection Database connection: PDO
     * @param  array  $options      Optional array of setting options
     * @return void
     */
    public function __construct($dbConnection, array $options = [])
    {
        if ($dbConnection instanceof PDO) {
            $this->dbh = $dbConnection;
        } else {
            throw new Exception("Invalid database connection provided, expected PDO");
        }

        $this->now = date('Y-m-d H:i:s');
        $this->today = date('Y-m-d');
        $this->setConfig($options);
    }

    /**
     * Create a new Domain Value Object
     *
     * Uses the $domainObjectClass defined in the child class
     * @param  void
     * @return DomainObject
     */
    public function make()
    {
        $fullyQualifedClassName = $this->domainObjectClass;

        return new $fullyQualifedClassName;
    }

    /**
     * Find one table row using the primary key ID
     *
     * @param  int   $id Primary key ID
     * @return mixed     DomainObject | null
     */
    public function findById($id)
    {
        // Use default select statement and add where clause, unless other SQL has been supplied
        if (empty($this->sql)) {
            $this->makeSelect();
            $this->sql .= ' and ';
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
     * @param  void
     * @return mixed DomainObject | null
     */
    public function findRow()
    {
        if (!$this->sql) {
            $this->makeSelect();
        }

        // Execute the query & return
        $this->execute();

        return $this->statement->fetch();
    }

    /**
     * Find Table Rows
     *
     * Returns all matching table rows.
     * @param  bool $foundRows Set to true to get foundRows() after query
     * @return mixed Array of DomainObject | null
     */
    public function find($foundRows = false)
    {
        // Use default select statement unless other SQL has been supplied
        if (!$this->sql) {
            $this->makeSelect($foundRows);
        }

        // Execute the query
        $this->execute();

        return $this->statement->fetchAll();
    }

    /**
     * Count Found Rows
     *
     * Returns the total number of rows for the last query if SQL_CALC_FOUND_ROWS was set
     * @param  void
     * @return int
     */
    public function foundRows()
    {
        return $this->dbh->query('select found_rows()')->fetch(PDO::FETCH_COLUMN);
    }

    /**
     * Save Domain Object
     *
     * Define in child class to add any manipulation before calling parent::coreSave()
     * @param  DomainObject $domainObject
     * @return mixed                      DomainObject | null
     */
    public function save(DomainObject $domainObject)
    {
        return $this->coreSave($domainObject);
    }

    /**
     * Update a Record
     *
     * Define in child class to add any manipulation before calling parent::coreUpdate()
     * @param  DomainObject $domainObject
     * @return mixed                      DomainObject | null
     */
    public function update(DomainObject $domainObject)
    {
        return $this->coreUpdate($domainObject);
    }

    /**
     * Insert a Record
     *
     * Define in child class to add any manipulation before calling parent::coreInsert()
     * @param  DomainObject $domainObject
     * @param  bool                       If true, update on duplicate record
     * @return mixed                      DomainObject | null
     */
    public function insert(DomainObject $domainObject, $ignore = false)
    {
        return $this->coreInsert($domainObject, $ignore);
    }

    /**
     * Delete a Record
     *
     * Define in child class to override behavior before calling parent::coreDelete()
     * @param  DomainObject $domainObject
     * @return bool                       true | null
     */
    public function delete(DomainObject $domainObject)
    {
        return $this->coreDelete($domainObject);
    }

    /**
     * Current Date Time
     *
     * Returns datetime string in MySQL Format
     * @param  void
     * @return string
     */
    public function now()
    {
        return $this->now;
    }

    /**
     * Current Date
     *
     * Returns date string in MySQL Format
     * @param  void
     * @return string
     */
    public function today()
    {
        return $this->today;
    }

    // ------------------------------------------------------------------------
    // Protected Methods
    // ------------------------------------------------------------------------

    /**
     * Save Domain Object
     *
     * Inserts or updates Domain Object record
     * @param  DomainObject $domainObject
     * @return mixed                      DomainObject | null
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
     * Update a Record
     *
     * Updates a single record using the primarky key ID
     * @param  DomainObject $domainObject
     * @return mixed                      DomainObject | null
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
        foreach ($this->modifiableColumns as $column) {
            if (property_exists($domainObject, $column)) {
                $this->sql .= $column . ' = ?, ';
                $this->bindValues[] = $domainObject->$column;
            }
        }

        // Remove last comma at end of SQL string
        $this->sql = rtrim($this->sql, ', ');

        // Set Who columns
        if ($this->who) {
            $this->sql .= ', updated_by = ?, updated_date = ? ';
            $this->bindValues[] = $this->sessionUserId;
            $this->bindValues[] = $this->now;

            // Set domain object properties for reference on return
            $domainObject->updated_by = $this->sessionUserId;
            $domainObject->updated_date = $this->now;
        }

        // Append where clause
        $this->sql .= ' where ' . $this->primaryKey . ' = ?;';
        $this->bindValues[] = $domainObject->{$this->primaryKey};

        // Execute
        $this->execute();

        return $domainObject;
    }

    /**
     * Insert a New Record
     *
     * @param  DomainObject $domainObject
     * @param  bool         $ignore If true, update on duplicate record
     * @return mixed        DomainObject | null
     */
    protected function coreInsert(DomainObject $domainObject, $ignore = false)
    {
        // Get started
        $this->sql = 'insert ';
        $this->sql .= ($ignore) ? 'ignore ' : '';
        $this->sql .= 'into ' . $this->table . ' (';

        // Insert values placeholder string
        $insertValues = ' ';

        // Use set object properties which match the list of updatable columns
        foreach ($this->modifiableColumns as $column) {
            if (property_exists($domainObject, $column)) {
                $this->sql .= $column . ', ';
                $insertValues .= '?, ';
                $this->bindValues[] = $domainObject->$column;
                // $hasBeenSet++;
            }
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
            $this->bindValues[] = $this->now;
            $this->bindValues[] = $this->sessionUserId;
            $this->bindValues[] = $this->now;

            // Set domain object properties for reference on return
            $domainObject->created_by = $this->sessionUserId;
            $domainObject->created_date = $this->now;
            $domainObject->updated_by = $this->sessionUserId;
            $domainObject->updated_date = $this->now;
        }

        // Close and concatenate strings
        $this->sql .= ') values (' . $insertValues . ');';

        // Execute and assign last insert ID to primary key and return
        $this->execute();
        $domainObject->{$this->primaryKey} = $this->dbh->lastInsertId();

        return $domainObject;
    }

    /**
     * Delete a Record
     *
     * @param  DomainObject $domainObject
     * @return bool         true | null
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
     * @param  bool $foundRows Set to true to get foundRows() after query
     * @return void
     */
    protected function makeSelect($foundRows = false)
    {
        if (!isset($this->sql)) {
            $this->sql = 'select SQL_CALC_FOUND_ROWS ';
            $this->sql .= $foundRows ? 'SQL_CALC_FOUND_ROWS ' : '';
            $this->sql .= ($this->tableAlias) ?: $this->table;
            $this->sql .= '.* from ' . $this->table . ' ' . $this->tableAlias;
            $this->sql .= ' where 1=1';
        }
    }

    /**
     * Clear Prior SQL Statement
     *
     * Resets $sql, $bindValues, and $fetchMode
     * Called after executing prior statement
     * @param  void
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
     * @param  void
     * @return mixed true | null
     */
    protected function execute()
    {
        // Log query and binds
        if ($this->logger) {
            $this->logger->debug('PitonORM: SQL: ' . $this->sql);
            $this->logger->debug('PitonORM: SQL Binds: ' . print_r($this->bindValues, true));
        }

        // Prepare the query
        $this->statement = $this->dbh->prepare($this->sql);

        // Bind values
        foreach ($this->bindValues as $key => $value) {
            // Determine data type
            if (is_int($value)) {
                $paramType = PDO::PARAM_INT;
            } elseif ($value === null || $value === '') {
                $paramType = PDO::PARAM_NULL;
            } else {
                $paramType = PDO::PARAM_STR;
            }

            $this->statement->bindValue($key + 1, $value, $paramType);
        }

        // Execute the query
        if (false === $outcome = $this->statement->execute()) {
            // If false is returned there was a problem
            if ($this->logger) {
                $this->logger->error('PitonORM: PDO Execute Returned False: ' . $this->sql);
                $this->logger->error('PitonORM: PDO SQL Binds: ' . print_r($this->bindValues, true));
                $this->logger->error('PitonORM: PDO errorInfo: ' . print_r($this->statement->errorInfo(), true));
            }

            return null;
        }

        // If a select statement was executed, set fetch mode
        if (stristr($this->sql, 'select')) {
            if ($this->fetchMode === PDO::FETCH_CLASS) {
                $this->statement->setFetchMode($this->fetchMode, $this->domainObjectClass);
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
     * @param  array $table Table definition
     * @return void
     */
    protected function define($table)
    {
        // Required, table name
        if (isset($table['table'])) {
            $this->table = $table['table'];
        } else {
            throw new Exception("Option 'table' name must be defined.");
        }

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

    /**
     * Set Configuration
     *
     * Set DataMapper configuration options.
     * @param  array $options Array of configuration options
     * @return void
     */
    private function setConfig($options)
    {
        if (empty($options)) {
            return;
        }

        if (isset($options['logger'])) {
            if (is_object($options['logger'])) {
                $this->logger = $options['logger'];
            } else {
                throw new Exception("Option 'logger' must be a logging object");
            }
        }

        if (isset($options['sessionUserId'])) {
            $this->sessionUserId = $options['sessionUserId'];
        }
    }
}
