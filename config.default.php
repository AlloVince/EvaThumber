<?php
return array(
    'opencv_path' => 'c:\jpegtran.exe',
    'jpegtran_path' => 'c:\jpegtran.exe',
    'pngout_path' => 'c:\pngout.exe',
    'error_url' => 'http://localhost/EvaCloudImage/error.png',
    'thumbers' => array(
        '1' => array(
            'debug' => 0, //0: redirect to error png | 1: redirect to error png with error url msg | 2: throw an exception
            'source_path' => __DIR__ . '/upload',
            'thumb_cache_path' => __DIR__ . '/thumb',
            'system_cache_path' => null,
            'adapter' => 'GD', //GD | Imagick | Gmagick
            'prefix' => 'thumb', //if no prefix, will use array key
            'cache' => 0,
            'error_url' => 'http://localhost/EvaCloudImage/error.png',
            'allow_stretch' => false,
            'min_width' => 10,
            'min_height' => 10,
            'max_width' => 800,
            'max_height' => 400,
            'quality' => 70,
            'redirect_referer' => true, 
            'allow_sizes' => array(
                //Suggest keep empty here to be overwrite
            ),
            'disable_operates' => array(
                //Suggest keep empty here to be overwrite
            ),
            'watermark' => array(
                'enable' => 0,
                'position' => 'br', //position could be tl:TOP LEFT | tr: TOP RIGHT | bl | BOTTOM LEFT | br BOTTOM RIGHT | center
                'text' => '@AlloVince',
                'layer_file' => __DIR__ . '/layers/watermark.png',
                'font_file' => __DIR__ . '/layers/Yahei_Mono.ttf',
                'font_size' => 12,
                'font_color' => '#FFFFFF',
            ),
        ),
        '2' => array(
            'source_path' => 'E:\WallPaper',
            'allow_sizes' => array(
                '200*100',
                '100*100',
            ),
            'disable_operates' => array(
                /*
                'filter',
                'crop',
                'dummy'
                */
            ),
            'watermark' => array(
                'enable' => 1,
                'position' => 'br', //position could be tl:TOP LEFT | tr: TOP RIGHT | bl | BOTTOM LEFT | br BOTTOM RIGHT | center
                'text' => '@AlloVince',
                'layer_file' => '',
                'font_file' => __DIR__ . '/layers/Yahei_Mono.ttf',
                'font_size' => 12,
                'font_color' => '#FFFFFF',
            ),
        ),
    ),
);
