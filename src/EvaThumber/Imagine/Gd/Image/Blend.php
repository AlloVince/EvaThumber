<?php

/*
 * This file is part of the Imagine package.
 *
 * (c) Bulat Shakirzyanov <mallluhuct@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EvaThumber\Imagine\Gd\Image;

use Imagine\Exception\RuntimeException;
use EvaThumber\Imagine\Blend\AbstractBlend;

/**
 * Blend implementation using the GD library
 */
class Blend extends AbstractBlend
{
    /*
    public static function layerOverlay($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //叠加
        $width = imagesx($dstSource);
        $height = imagesy($dstSource);
        $layer = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $color = imagecolorat($dstSource, $x, $y);
                $dR = ($color >> 16) & 0xFF;
                $dG = ($color >> 8) & 0xFF;
                $dB = $color & 0xFF;
                $color = imagecolorat($srcSource, $x, $y);
                $sR = ($color >> 16) & 0xFF;
                $sG = ($color >> 8) & 0xFF;
                $sB = $color & 0xFF;

                imagesetpixel($dstSource, $x, $y, imagecolorallocate($srcSource, $dR * $sR / 255, $dG * $sG / 255, $dB * $sB / 255));
            }
        }
    }
    */
}
