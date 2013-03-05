<?php
/**
 * EvaThumber
 * URL based image transformation php library
 *
 * @link      https://github.com/AlloVince/EvaThumber
 * @copyright Copyright (c) 2012-2013 AlloVince (http://avnpc.com/)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 * @author    AlloVince
 */

namespace EvaThumber\Config;

use EvaThumber\Exception;
use ArrayAccess;
use Countable;
use Iterator;

class StandardConfig implements ConfigInterface, Iterator, ArrayAccess
{
    /**
     * All options
     *
     * @var array
     */
    protected $options = array();

    protected $optionString = '';

    /**
     * Set many options at once
     *
     * If a setter method exists for the key, that method will be called;
     * otherwise, a standard option will be set with the value provided via
     * {@link setOption()}.
     *
     * @param  array|Traversable $options
     * @return StandardConfig
     * @throws Exception\InvalidArgumentException
     */
    public function setOptions($options)
    {
        if (!is_array($options) && !$options instanceof Traversable) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Parameter provided to %s must be an array or Traversable',
                __METHOD__
            ));
        }

        foreach ($options as $key => $value) {
            $setter = 'set' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
            if (method_exists($this, $setter)) {
                $this->{$setter}($value);
            } else {
                $this->setOption($key, $value);
            }
        }
        return $this;
    }

    /**
     * Get all options set
     *
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Set an individual option
     *
     * Keys are normalized to lowercase. After setting internally, calls
     * {@link setStorageOption()} to allow further processing.
     *
     *
     * @param  string $option
     * @param  mixed $value
     * @return StandardConfig
     */
    public function setOption($option, $value)
    {
        $option                 = strtolower($option);
        $this->options[$option] = $value;
        return $this;
    }

    /**
     * Get an individual option
     *
     * Keys are normalized to lowercase. If the option is not found, attempts
     * to retrieve it via {@link getStorageOption()}; if a value is returned
     * from that method, it will be set as the internal value and returned.
     *
     * Returns null for unfound options
     *
     * @param  string $option
     * @return mixed
     */
    public function getOption($option)
    {
        $option = strtolower($option);
        if (array_key_exists($option, $this->options)) {
            return $this->options[$option];
        }

        return null;
    }

    /**
     * Check to see if an internal option has been set for the key provided.
     *
     * @param  string $option
     * @return bool
     */
    public function hasOption($option)
    {
        $option = strtolower($option);
        return array_key_exists($option, $this->options);
    }

    public function fromString($optionString)
    {
        $this->optionString = $optionString;
        return $this;
    }

    public function toString()
    {
        return $this->optionString;
    }

    public function toArray()
    {
        return $this->options;
    }

    /**
     * Intercept get*() and set*() methods
     *
     * Intercepts getters and setters and passes them to getOption() and setOption(),
     * respectively.
     *
     * @param  string $method
     * @param  array $args
     * @return mixed
     * @throws Exception\BadMethodCallException on non-getter/setter method
     */
    public function __call($method, $args)
    {
        $prefix = substr($method, 0, 3);
        $option = substr($method, 3);
        $key    = strtolower(preg_replace('#(?<=[a-z])([A-Z])#', '_\1', $option));

        if ($prefix === 'set') {
            $value  = array_shift($args);
            return $this->setOption($key, $value);
        } elseif ($prefix === 'get') {
            return $this->getOption($key);
        } else {
            throw new Exception\BadMethodCallException(sprintf(
                'Method "%s" does not exist in %s',
                $method,
                get_called_class()
            ));
        }
    }
}
