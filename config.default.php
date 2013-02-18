<?php
return array(
    'error_url' => 'http://localhost/EvaCloudImage/error.png',
    'thumbers' => array(
        'default' => array(
            'source_path' => __DIR__ . '/upload',
            'cache_path' => __DIR__ . '/thumb',
            'adapter' => 'GD', //GD | Imagick | Gmagick
            'prefix' => 'thumb', //if no prefix, will use array key
            'cache' => false,
            'error_url' => 'http://localhost/EvaCloudImage/error.png',
            'allow_stretch' => false,
            'max_file_size' => '1M',
            'max_width' => 2000,
            'max_height' => 1000,
            'quality' => 70,
            'allow_sizes' => array(
                'size1' => '200*100',
                'size2' => '100*100',
            ),
            'disable_operates' => array(
                'f' => 'filter',
                'c' => 'crop',
            ),
            'allow_filters' => array(
            ),
            'watermark' => array(
                'enable' => false,
                'position' => '',
                'text' => 'watermark',
                'layer_file' => '',
                'font_file' => '',
                'font_size' => '',
                'font_color' => '',
            ),
        ),
    ),
);
