<?php
return array(
    'error_url' => 'http://localhost/EvaCloudImage/error.png',
    'thumbers' => array(
        'default' => array(
            'debug' => 0, //0: redirect to error png | 1: redirect to error png with error url msg | 2: throw an exception
            'source_path' => __DIR__ . '/upload',
            'thumb_cache_path' => __DIR__ . '/thumb',
            'system_cache_path' => null,
            'adapter' => 'GD', //GD | Imagick | Gmagick
            'prefix' => 'thumb', //if no prefix, will use array key
            'cache' => false,
            'error_url' => 'http://localhost/EvaCloudImage/error.png',
            'allow_stretch' => false,
            'max_file_size' => '1M',
            'min_width' => 10,
            'min_height' => 10,
            'max_width' => 2000,
            'max_height' => 1000,
            'quality' => 70,
            'redirect_referer' => true, 
            'allow_sizes' => array(
                'size1' => '200*100',
                'size2' => '100*100',
            ),
            'disable_operates' => array(
                'f' => 'filter',
                'c' => 'crop',
                'd' => 'dummy'
            ),
            'allow_filters' => array(
                'gray' => 'gray',
            ),
            'watermark' => array(
                'enable' => 1,
                'position' => 'br', //position could be tl:TOP LEFT | tr: TOP RIGHT | bl | BOTTOM LEFT | br BOTTOM RIGHT | center
                'text' => '@AlloVince',
                'layer_file' => __DIR__ . '/layers/watermark.png',
                'font_file' => __DIR__ . '/layers/Yahei_Mono.ttf',
                'font_size' => 12,
                'font_color' => '#FFFFFF',
            ),
        ),
        '1' => array(
            'source_path' => 'E:\WallPaper',
        ),
    ),
);
