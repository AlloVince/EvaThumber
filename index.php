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

error_reporting(E_ALL);

// Check php version
if( version_compare(phpversion(), '5.3.0', '<') ) {
    printf('PHP 5.3.0 is required, you have %s', phpversion());
    exit(1);
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    $loader = include __DIR__ .  '/vendor/autoload.php';
} else {
    die('Dependent library not found, run "composer install" first.');
}

/** Debug functions */
function p($r, $usePr = false)
{
    echo '<pre>' . var_dump($r); 
}

$loader->add('EvaThumber', __DIR__ . '/src');

$config = new EvaThumber\Config\Config(include __DIR__ . '/config.default.php');
$localConfig = __DIR__ . '/config.local.php';
if(file_exists($localConfig)){
    $localConfig = new EvaThumber\Config\Config(include $localConfig);
    $config = $config->merge($localConfig);
}

$thumber = new EvaThumber\Thumber($config);

try {
    $thumber->show();
} catch(Exception $e){
    throw $e;
    //header('location:' . $config->error_url . '?msg=' . urlencode($e->getMessage()));
}
