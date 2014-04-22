<?php
return array(
    'thumbers' => array(
        'd' => array(
            'debug' => 0, //0: redirect to error png | 1: redirect to error png with error url msg | 2: throw an exception
            'source_path' => __DIR__ . '/upload',
            'system_file_encoding' => 'UTF-8',
            'zip_file_encoding' => 'GB2312',
            'thumb_cache_path' => __DIR__ . '/thumb',
            'system_cache_path' => null,
            'adapter' => 'GD', //GD | Imagick | Gmagick
            'prefix' => 'thumb', //if no prefix, will use array key
            'cache' => 0,
            'error_url' => 'http://localhost/EvaCloudImage/error.png',
            'allow_stretch' => false,
            //'min_width' => 10,
            //'min_height' => 10,
            'max_width' => 2000,
            'max_height' => 2000,
            'quality' => 100,
            'blending_layer' => __DIR__ . '/upload/blend.png',
            'redirect_referer' => true, 
            'face_detect' => array(
                'enable' => 0,
                'draw_border' => 1,
                'cascade' => __DIR__ . '/data/haarcascades/haarcascade_frontalface_alt.xml',
                'bin' => __DIR__ . '/bin/opencv.py',
            ),
            'png_optimize' => array(
                'enable' => 0,
                'adapter' => 'pngout',
                'pngout' => array(
                    'bin' => __DIR__ . '/bin/pngout.exe',
                ),
            ),
            'allow_extensions' => array(
            
            ),
            'allow_sizes' => array(
                //Suggest keep empty here to be overwrite
                //'200*100',
                //'100*100',
            ),
            'disable_operates' => array(
                //Suggest keep empty here to be overwrite
                //'filter',
                //'crop',
                //'dummy',
            ),
            'watermark' => array(
                'enable' => 0,
                'position' => 'br', //position could be tl:TOP LEFT | tr: TOP RIGHT | bl | BOTTOM LEFT | br BOTTOM RIGHT | center
                'text' => '@AlloVince',
                'layer_file' => __DIR__ . '/layers/watermark.png',
                'font_file' => __DIR__ . '/layers/Yahei_Mono.ttf',
                'font_size' => 12,
                'font_color' => '#FFFFFF',
                'qr_code' => 0,
                'qr_code_size' => 3,
                'qr_code_margin' => 4,
            ),
        ),
    ),
);
