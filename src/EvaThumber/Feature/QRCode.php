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


namespace EvaThumber\Feature;

use PHPQRCode;

class QRCode implements FeatureInterface
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
