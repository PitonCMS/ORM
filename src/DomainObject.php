<?php
/**
 * PitonCMS (https://github.com/PitonCMS)
 *
 * @link      https://github.com/PitonCMS
 * @copyright Copyright (c) 2015 - 2019 Wolfgang Moritz
 * @license   https://github.com/PitonCMS/ORM/blob/master/LICENSE (MIT License)
 */
namespace Piton\ORM;

/**
 * Piton Domain Value Object
 *
 * Base class for all domain value objects
 * Extend this class to include custom property management on __set() or __get().
 */
class DomainObject
{
    /**
     * This $id avoids an error when the __get() magic method in DomainObjectAbstract is called
     * on a non-existent property
     * @var int
     */
    public $id;

    /**
     * Get Object Property
     *
     * Applies only to private and protected properties
     */
    public function __get($key)
    {
        return isset($this->$key) ? $this->$key : null;
    }

    /**
     * Set Object Property
     *
     * Applies only to private and protected properties
     */
    public function __set($key, $value)
    {
        $this->$key = $value;
    }
}
