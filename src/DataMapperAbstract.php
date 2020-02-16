<?php

/**
 * PitonCMS (https://github.com/PitonCMS)
 *
 * @link      https://github.com/PitonCMS/ORM
 * @copyright Copyright (c) 2015 - 2019 Wolfgang Moritz
 * @license   https://github.com/PitonCMS/ORM/blob/master/LICENSE (MIT License)
 */

declare(strict_types=1);

namespace Piton\ORM;

use PDO;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Piton Abstract Data Mapper Class
 *
 * All data mapper classes for tables should extend this class.
 * @version 0.3.5
 */
abstract class DataMapperAbstract
{
    // ------------------------------------------------------------------------
    // Define these properties in the child class
    // ------------------------------------------------------------------------

    /**
     * Table Name
     * @var string
     */
    protected $table;

    /**
     * Primary Key Column Name
     * Define if not 'id'
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Updatable or Insertable Columns, not including the who columns
     * @var array
     */
    protected $modifiableColumns = [];

    /**
     * Domain Object Class
     * @var string
     */
    protected $domainObjectClass;

    /**
     * Does this table have 'created_by', 'created_date', 'updated_by', and 'updated_date' columns?
     * @var boolean
     */
    protected $who = true;

    // ------------------------------------------------------------------------
    // Do not directly set properties below, these are set at runtime
    // ------------------------------------------------------------------------

    /**
     * Database Connection Object
     * @var PDO Connection Object
     */
    private $dbh;

    /**
     * PDO Fetch Mode
     * @var PDO Fetch Mode Constant
     */
    protected $fetchMode = PDO::FETCH_CLASS;

    /**
     * Session User ID
     * @var mixed
     */
    protected $sessionUserId;

    /**
     * PSR 3 Logging Interface
     * @var Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * SQL Statement to Execute
     * @var string
     */
    protected $sql;

    /**
     * Bind Values
     * @var array
     */
    protected $bindValues = [];

    /**
     * Statement Being Executed
     * @var PDO Statement Object
     */
    protected $statement;

    /**
     * Now 'Y-m-d H:i:s'
     * @var string
     */
    protected $now;

    /**
     * Today 'Y-m-d'
     * @var string
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
    public function __construct(PDO $dbConnection, array $options = [])
    {
        if ($dbConnection instanceof PDO) {
            $this->dbh = $dbConnection;
        } else {
            throw new Exception("Invalid database connection provided, expected PDO");
        }

        $this->now = date('Y-m-d H:i:s');
        $this->today = date('Y-m-d');
        $this->setConfig($options);

        // If domainObjectClass property was not set in child class,
    }

    /**
     * Make DomainValue Object
     *
     * Uses the $domainObjectClass defined in the child class
     * Or defaults to DomainObject
     * @param  void
     * @return DomainObject
     */
    public function make(): DomainObject
    {
        return new $this->domainObjectClass;
    }

    /**
     * Find by ID
     *
     * Find one table row using the primary key ID
     * @param  int   $id Primary key ID
     * @return DomainObject|null
     */
    public function findById(int $id): ?DomainObject
    {
        // Use default select statement and add where clause, unless other SQL has been supplied
        if (empty($this->sql)) {
            $this->makeSelect();
        }

        $this->sql .= " and {$this->table}.{$this->primaryKey} = ?";
        $this->bindValues[] = $id;

        return $this->findRow();
    }

    /**
     * Find Single Record
     *
     * Use if the SQL is expecting one row
     * @param  void
     * @return DomainObject|null
     */
    public function findRow(): ?DomainObject
    {
        if (!$this->sql) {
            $this->makeSelect();
            $this->sql .= ' limit 1';
        }

        // Execute the query & return
        if ($this->execute()) {
            return $this->statement->fetch() ?: null;
        }

        return null;
    }

    /**
     * Find Table Rows
     *
     * Returns all matching table rows.
     * @param  bool $foundRows Set to true to get foundRows() after query
     * @return array|null
     */
    public function find(bool $foundRows = false): ?array
    {
        // Use default select statement unless other SQL has been supplied
        if (!$this->sql) {
            $this->makeSelect($foundRows);
        }

        // Execute the query
        if ($this->execute()) {
            return $this->statement->fetchAll() ?: null;
        }

        return null;
    }

    /**
     * Count Found Rows
     *
     * Returns the total number of rows for the last query if SQL_CALC_FOUND_ROWS was set
     * @param  void
     * @return int
     */
    public function foundRows(): ?int
    {
        return (int) $this->dbh->query('select found_rows()')->fetch(PDO::FETCH_COLUMN) ?: null;
    }

    /**
     * Save Domain Object
     *
     * Override in child class to add any manipulation before calling parent::coreSave()
     * @param  DomainObject $domainObject
     * @return DomainObject|null
     */
    public function save(DomainObject $domainObject): ?DomainObject
    {
        return $this->coreSave($domainObject);
    }

    /**
     * Update a Record
     *
     * Override in child class to add any manipulation before calling parent::coreUpdate()
     * @param  DomainObject $domainObject
     * @return DomainObject|null
     */
    public function update(DomainObject $domainObject): ?DomainObject
    {
        return $this->coreUpdate($domainObject);
    }

    /**
     * Insert a Record
     *
     * Override in child class to add any manipulation before calling parent::coreInsert()
     * @param  DomainObject $domainObject
     * @param  bool         $ignore       If true, update on duplicate record
     * @return DomainObject|null
     */
    public function insert(DomainObject $domainObject, bool $ignore = false): ?DomainObject
    {
        return $this->coreInsert($domainObject, $ignore);
    }

    /**
     * Delete a Record
     *
     * Override in child class to override behavior before calling parent::coreDelete()
     * @param  DomainObject $domainObject
     * @return bool
     */
    public function delete(DomainObject $domainObject): bool
    {
        return $this->coreDelete($domainObject);
    }

    /**
     * Current Date Time
     *
     * Returns datetime string in MySQL date time Format
     * @param  void
     * @return string
     */
    public function now(): string
    {
        return $this->now;
    }

    /**
     * Current Date
     *
     * Returns date string in MySQL date Format
     * @param  void
     * @return string
     */
    public function today(): string
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
    protected function coreSave(DomainObject $domainObject): ?DomainObject
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
     * @return DomainObject|null
     */
    protected function coreUpdate(DomainObject $domainObject): ?DomainObject
    {
        // Make sure a primary key was set
        if (empty($domainObject->{$this->primaryKey})) {
            throw new Exception('A primary key id was not provided to update the record.');
        }

        // Build SQL...
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
        if ($this->who && $this->sessionUserId) {
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
        if ($this->execute()) {
            return $domainObject;
        }

        return null;
    }

    /**
     * Insert a New Record
     *
     * @param  DomainObject $domainObject
     * @param  bool         $ignore If true, update on duplicate record
     * @return DomainObject|null
     */
    protected function coreInsert(DomainObject $domainObject, bool $ignore = false): ?DomainObject
    {
        // Build SQL...
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
            }
        }

        // Remove trailing commas
        $this->sql = rtrim($this->sql, ', ');
        $insertValues = rtrim($insertValues, ', ');

        // Set Who columns
        if ($this->who && $this->sessionUserId) {
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
        if ($this->execute()) {
            $domainObject->{$this->primaryKey} = (int) $this->dbh->lastInsertId();
            return $domainObject;
        }

        return null;
    }

    /**
     * Delete a Record
     *
     * @param  DomainObject $domainObject
     * @return bool
     */
    protected function coreDelete(DomainObject $domainObject): bool
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
     * Make select statement
     * Overrides and sets $this->sql.
     * @param  bool $foundRows Set to true to get foundRows() after query
     * @return void
     */
    protected function makeSelect(bool $foundRows = false)
    {
        $modifier = $foundRows ? 'SQL_CALC_FOUND_ROWS ' : '';
        $this->sql = "select $modifier {$this->table}.* from {$this->table} where 1=1 ";
    }

    /**
     * Clear Prior SQL Statement
     *
     * Resets $sql, $bindValues, and $fetchMode
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
     * Returns true/false
     * @param  void
     * @return bool
     */
    protected function execute(): bool
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
            $this->clear();
            return $outcome;
        }

        // If a select statement was executed, set fetch mode
        if (stristr($this->sql, 'select')) {
            if ($this->fetchMode === PDO::FETCH_CLASS) {
                $this->statement->setFetchMode($this->fetchMode, $this->domainObjectClass);
            } else {
                $this->statement->setFetchMode($this->fetchMode);
            }
        }

        $this->clear();
        return $outcome;
    }

    /**
     * Set Configuration
     *
     * Set DataMapper configuration options.
     * @param  array $options Array of configuration options
     * @return void
     */
    private function setConfig(array $options)
    {
        if (isset($options['logger'])) {
            if ($options['logger'] instanceof LoggerInterface) {
                $this->logger = $options['logger'];
            } else {
                throw new Exception("PitonORM: Option 'logger' must be an instance of Psr\Log\LoggerInterface");
            }
        }

        if (isset($options['sessionUserId'])) {
            $this->sessionUserId = (int) $options['sessionUserId'];
        }

        // Set domainObjectClass using 1) Child class property, 2) Runtime provided default, 3) This \DomainObject
        $this->domainObjectClass = $this->domainObjectClass ?? $options['defaultDomainObjectClass'] ?? __NAMESPACE__ . '\DomainObject';
    }
}
