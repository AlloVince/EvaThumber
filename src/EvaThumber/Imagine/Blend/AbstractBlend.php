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

    protected static function pixelHandler($dstImage, $srcImage, $handler = null)
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
                $color = $srcImage->getColorAt($point);
                $sR = $color->getRed();
                $sG = $color->getGreen();
                $sB = $color->getBlue();
                $handler($point, $dR, $dG, $dB, $sR, $sG, $sB);
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
    }

    public static function layerMultiply($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //正片叠底
    }

    public static function layerColorBurn($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //颜色加深
    }

    public static function layerLinearBurn($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //线性加深
    }

    public static function layerLighten($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //变亮 
    }


    public static function layerScreen($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //滤色
    }

    public static function layerColorDodge($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //颜色减淡
    }

    public static function layerLinearDodge($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //线性减淡
    }

    public static function layerOverlay($dstImage, $srcImage, $dstSource, $srcSource)
    {
        //叠加
        self::pixelHandler($dstImage, $srcImage, function($point, $dR, $dG, $dB, $sR, $sG, $sB)
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
