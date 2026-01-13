<?php

/**
 * PitonCMS (https://github.com/PitonCMS)
 *
 * @link      https://github.com/PitonCMS/ORM
 * @copyright Copyright (c) 2015 - 2026 Wolfgang Moritz
 * @license   AGPL-3.0-or-later with Theme Exception. See LICENSE file for details.
 */

declare(strict_types=1);

namespace Piton\ORM;

use ReflectionException;
use ReflectionProperty;
use ReflectionUnionType;

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
    protected ?int $id = null;

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
                // Cast value to property type
                $value = $this->castValueToPropertyType($key, $value);
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

    /**
     * Confirms if Property is Explicitly Modified
     *
     * Used in DataMapperAbstract to valid if property should be used for update/insert
     * @param string $key
     * @return bool
     */
    public function isPropertyModified(string $key): bool
    {
        return isset($this->modifiedProperties[$key]);
    }

    /**
     * Set Property as Modified
     *
     * Flags property for update
     * @param string $key
     * @return void
     */
    public function setPropertyAsModified(string $key): void
    {
        $this->modifiedProperties[$key] = true;
    }

    /**
     * Cast Value to Property Type
     *
     * Avoids casting errors when assigning values to typed properties
     * @param string $propertyKey
     * @param mixed $value
     * @return mixed
     */
    protected function castValueToPropertyType(string $propertyKey, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Use reflection to get the property type
        try {
            $reflection = new ReflectionProperty($this, $propertyKey);
            $type = $reflection->getType();

            if ($type && !$type instanceof ReflectionUnionType) {
                $typeName = $type->getName();

                return match($typeName) {
                    'int' => (int) $value,
                    'float' => (float) $value,
                    'string' => (string) $value,
                    'bool' => (bool) $value,
                    default => $value
                };
            }
        } catch (ReflectionException $e) {
            // Property doesn't exist, return as-is
            return $value;
        }

        return $value;
    }
}
