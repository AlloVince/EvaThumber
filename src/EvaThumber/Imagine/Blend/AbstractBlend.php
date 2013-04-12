<?php

/*
 * This file is part of the Imagine package.
 *
 * (c) Bulat Shakirzyanov <mallluhuct@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EvaThumber\Imagine\Blend;

use Imagine\Exception\RuntimeException;
use Imagine\Image\Point;
use Imagine\Image\Color;

/**
 * Blend implementation using the GD library
 */
abstract class AbstractBlend
{
    protected $resource;

    protected static function pixelHandler($dstImage, $srcImage, $handler)
    {
        $width = $dstImage->getSize()->getWidth();
        $height = $dstImage->getSize()->getHeight();
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $point = new Point($x, $y);
                $color = $dstImage->getColorAt($point);
                $dR = $color->getRed();
                $dG = $color->getGreen();
                $dB = $color->getBlue();
                $dA = $color->getAlpha();
                $color = $srcImage->getColorAt($point);
                $sR = $color->getRed();
                $sG = $color->getGreen();
                $sB = $color->getBlue();
                $sA = $color->getAlpha();
                $handler($point, $dR, $dG, $dB, $dA, $sR, $sG, $sB, $sA);
            }
        }
    }

    public static function layerNormal($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //普通
    }

    public static function layerDissolve($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //溶解
    }

    public static function layerDarken($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //变暗
        self::pixelHandler($dstImage, $srcImage, function($point, $dR, $dG, $dB, $dA, $sR, $sG, $sB, $sA)
            use ($dstImage){
                $dstImage->draw()->dot($point, new Color(array(
                    $dR > $sR ? $sR : $dR,
                    $dG > $sG ? $sG : $dG,
                    $dB > $sB ? $sB : $dB
                )));
        });
    }

    public static function layerMultiply($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //正片叠底
        self::pixelHandler($dstImage, $srcImage, function($point, $dR, $dG, $dB, $dA, $sR, $sG, $sB, $sA)
            use ($dstImage){
                $dstImage->draw()->dot($point, new Color(array(
                    $dR * $sR / 255,
                    $dG * $sG / 255,
                    $dB * $sG / 255
                )));
        });
    }

    public static function layerColorBurn($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //颜色加深
    }

    public static function layerLinearBurn($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //线性加深
        self::pixelHandler($dstImage, $srcImage, function($point, $dR, $dG, $dB, $dA, $sR, $sG, $sB, $sA)
             use ($dstImage, $srcImage){
                 $dstImage->draw()->dot($point, new Color(array(
                     ($r = $dR + $sR) > 255 ? $r - 255 : 0,
                     ($g = $dG + $sG) > 255 ? $g - 255 : 0,
                     ($b = $dB + $sB) > 255 ? $b - 255 : 0
                 )));
        });
    }

    public static function layerLighten($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //变亮 
        self::pixelHandler($dstImage, $srcImage, function($point, $dR, $dG, $dB, $dA, $sR, $sG, $sB, $sA)
            use ($dstImage){
                $dstImage->draw()->dot($point, new Color(array(
                    $dR > $sR ? $dR : $sR,
                    $dG > $sG ? $dG : $sG,
                    $dB > $sB ? $dB : $sB
                )));
        });
    }


    public static function layerScreen($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //滤色
    }

    public static function layerColorDodge($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //颜色减淡
        //TODO
        self::pixelHandler($dstImage, $srcImage, function($point, $dR, $dG, $dB, $dA, $sR, $sG, $sB, $sA)
            use ($dstImage){
                $ssR = 255 - $sR == 0 ? 1 : 255 - $sR;
                $ssG = 255 - $sG == 0 ? 1 : 255 - $sG;
                $ssB = 255 - $sB == 0 ? 1 : 255 - $sB;
                $dstImage->draw()->dot($point, new Color(array(
                    $dR + $dR * $sR / (255 - $sR),
                    $dG + $dG * $sG / (255 - $sG),
                    $dB + $dB * $sB / (255 - $sB)
                )));
        });
    }

    public static function layerLinearDodge($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //线性减淡
        self::pixelHandler($dstImage, $srcImage, function($point, $dR, $dG, $dB, $dA, $sR, $sG, $sB, $sA)
             use ($dstImage, $srcImage){
                 $dstImage->draw()->dot($point, new Color(array(
                     ($r = $dR + $sR) > 255 ? 255 : $r,
                     ($g = $dG + $sG) > 255 ? 255 : $g,
                     ($b = $dB + $sB) > 255 ? 255 : $b
                 )));
        });
    }

    public static function layerOverlay($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //叠加
        self::pixelHandler($dstImage, $srcImage, function($point, $dR, $dG, $dB, $dA, $sR, $sG, $sB, $sA)
             use ($dstImage, $srcImage){
                $dstImage->draw()->dot($point, new Color(array($dR * $sR / 255, $dG * $sG / 255, $dB * $sB / 255)));
        });
    }

    public static function layerDiference($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //差值
    } 

    public static function layerExclusion($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //排除
    }

    public static function layerSoftLight($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //柔光
    }

    public static function layerVividLight($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //亮光
    }

    public static function layerLinearLight($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //线性光
        //TODO
        self::pixelHandler($dstImage, $srcImage, function($point, $dR, $dG, $dB, $dA, $sR, $sG, $sB, $sA)
             use ($dstImage, $srcImage){
                 $dstImage->draw()->dot($point, new Color(array(
                     ($r = $dR + 2 * $sR - 255) > 255 ? 255 : ($r < 0 ? 0 : $r),
                     ($g = $dG + 2 * $sG - 255) > 255 ? 255 : ($g < 0 ? 0 : $g),
                     ($b = $dB + 2 * $sB - 255) > 255 ? 255 : ($b < 0 ? 0 : $b)
                 )));
        });
    }

    public static function layerPinLight($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //点光
    }

    public static function layerHardMix($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //实色混合
    }

}
