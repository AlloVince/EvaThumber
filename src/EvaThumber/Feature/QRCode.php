<?php


namespace EvaThumber\Feature;

use PHPQRCode;

class QRCode
{

    public static function isSupport()
    {
        if(false === extension_loaded('gd')){
            return false;
        }

        if(false === class_exists('PHPQRCode\QRcode')){
            return false;
        }

        return true;
    }

    public static function generateQRCodeLayer($text, $size = 3, $margin = 4)
    {
        $path = tempnam(sys_get_temp_dir(), 'evathumber_qrcode');
        PHPQRCode\QRcode::png($text, $path, 'L', $size, $margin);
        return $path;
    }
}
