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

interface ConfigInterface
{
    public function setOptions($options);
    public function getOptions();

    public function setOption($option, $value);
    public function getOption($option);
    public function hasOption($option);

    public function toArray();
}
