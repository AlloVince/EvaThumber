<?php
/**
 * EvaCloudImage
 * light-weight url based image transformation php library
 *
 * @link      https://github.com/AlloVince/EvaCloudImage
 * @copyright Copyright (c) 2012 AlloVince (http://avnpc.com/)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 * @author    AlloVince
 */

require_once 'EvaCloudImage.php';
error_reporting(E_ALL);

// Check php version
if( version_compare(phpversion(), '5.3.0', '<') ) {
    printf('PHP 5.3.0 is required, you have %s', phpversion());
    exit(1);
}

$cloudImage = new EvaCloudImage(null, include 'config.inc.php');
$cloudImage->show();
