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
     * @var int
     */
    public ?int $id = null;

    /**
     * Constructor
     */
    public function __construct(?array $row)
    {
        $this->id = isset($row['id']) ? (int) $row['id'] : null;
    }

    /**
     * Get Object Property
     *
     * @param string $key Property name to get
     * @return mixed Property value | null
     */
    public function __get(string $key)
    {
        return isset($this->$key) ? $this->$key : null;
    }

    /**
     * Set Object Property
     *
     * @param  string $key   Property key
     * @param  mixed|null  $value Property value to set
     */
    public function __set(string $key, $value = null)
    {
        $this->$key = $value;
    }
}
