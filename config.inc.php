<?php
return array(
    //'debug' => false,
    //'error_redirect' => 'http://avnpc.com/pages/evacloudimage',
    'classPath' => __DIR__ . '/EvaCloudImage.php',  //EvaCloudImage class file path
    'libPath' => __DIR__ . '/lib',  //PHPthumb library path
    'sourceRootPath' => __DIR__ . '/upload',  //original image save path, require path read permission
    'thumbFileRootPath' => __DIR__ . '/thumb', //resized thumbnails save path, require read and write permission
    'thumbUrlRootPath' => __DIR__ . '/..' , //thumbnails url root path, require read and write permission
    'saveImage' => false,  //if true, thumbnails will be created and auto save as same directory structure as original images
);
