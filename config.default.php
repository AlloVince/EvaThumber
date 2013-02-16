<?php
return array(
    'websiteRootPath' => '',
    'thumbRootPath' => __DIR__,
    'thumbAdapter' => 'Imagick',
    'thumbDirName' => 'thumb',
    'debug' => true,
    'configCache' => false,
    'globalCache' => true,
    'errorUrl' => '',
    'fileSources' => array(
        'default' => array(
            'prefix' => 'pic', //if no prefix, will use array key
            'sourcePath' => '',
            'cache' => false,
            'allowStretch' => false,
            'maxFilesize' => '1M',
            'maxWidth' => '2000',
            'maxHeight' => '1000',
            'errorUrl' => '',
            'allowSizes' => array(
                'size1' => '200*100',
                'size2' => '100*100',
            ),
            'watermark' => array(
                'enable' => false,
                'enableWidth' => 500,
                'enableHeight' => 400,
                'position' => '',
                'text' => 'watermark',
                'font' => '',
                'fontfile' => '',
            ),
        ),
    ),
);
