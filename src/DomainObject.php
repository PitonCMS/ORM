<?php

/**
 * PitonCMS (https://github.com/PitonCMS)
 *
 * @link      https://github.com/PitonCMS/ORM
 * @copyright Copyright (c) 2015 - 2026 Wolfgang Moritz
 * @license   https://github.com/PitonCMS/ORM/blob/master/LICENSE (MIT License)
 */

declare(strict_types=1);

namespace Piton\ORM;

/**
 * Piton Domain Value Object
 *
 * Base class for all domain value objects
 * Extend this class to include custom property management on __set() or __get().
 */
abstract class DomainObject
{
    /**
     * Track column-properties that have been explicitly set for update/insert, including to null
     *
     * @var array
     */
    protected array $modifiedProperties = [];

    /**
     * @var int
     */
    public ?int $id = null;

    /**
     * Constructor
     *
     * If an array of data is provided, the constructor attempts to assign the values to class properties.
     * This will not by default track modified properties.
     * @param array $row
     */
    public function __construct(?array $row = null)
    {
        if ($row) {
            foreach ($row as $key => $value) {
                $this->$key = $value;
            }
        }
    }

    /**
     * Get Object Property
     *
     * @param string $key Property name
     * @return mixed Property value
     */
    public function __get(string $key)
    {
        return $this->$key ?? null;
    }

    /**
     * Set Object Property
     *
     * @param  string $key   Property name
     * @param  mixed  $value Property value to set
     */
    public function __set(string $key, mixed $value = null)
    {
        $this->$key = $value;
    }

    public function isPropertyModified(string $key): bool
    {
        return isset($this->modifiedProperties[$key]);
    }
}
