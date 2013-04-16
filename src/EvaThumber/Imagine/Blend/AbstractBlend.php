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

    protected static function pixelHandler($topImage, $bottomImage, $handler)
    {
        $width = $topImage->getSize()->getWidth();
        $height = $topImage->getSize()->getHeight();

        if($topImage === $bottomImage){
            for ($x = 0; $x < $width; $x++) {
                for ($y = 0; $y < $height; $y++) {
                    $point = new Point($x, $y);
                    $color = $topImage->getColorAt($point);
                    $tR = $color->getRed();
                    $tG = $color->getGreen();
                    $tB = $color->getBlue();
                    $tA = $color->getAlpha();
                    $topImage->draw()->dot($point, new Color(array(
                        $handler($tR, $bR),
                        $handler($tG, $bG),
                        $handler($tB, $bB)
                    )));
                }
            }
        } else {
            for ($x = 0; $x < $width; $x++) {
                for ($y = 0; $y < $height; $y++) {
                    $point = new Point($x, $y);
                    $color = $topImage->getColorAt($point);
                    $tR = $color->getRed();
                    $tG = $color->getGreen();
                    $tB = $color->getBlue();
                    $tA = $color->getAlpha();
                    $color = $bottomImage->getColorAt($point);
                    $bR = $color->getRed();
                    $bG = $color->getGreen();
                    $bB = $color->getBlue();
                    $bA = $color->getAlpha();

                    $topImage->draw()->dot($point, new Color(array(
                        $handler($tR, $bR),
                        $handler($tG, $bG),
                        $handler($tB, $bB)
                    )));
                }
            }
        }



    }

    public static function layerLighten($topImage, $bottomImage)
    {
        //变亮 
        //(B > A) ? B:A
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return $B > $A ? $B : $A;
        });
    }


    public static function layerDarken($topImage, $bottomImage)
    {
        //变暗
        //(B > A) ? A:B
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return $B > $A ? $A : $B;
        });
    }

    public static function layerMultiply($topImage, $bottomImage)
    {
        //正片叠底
        //(A * B) / 255
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return $A * $B / 255;
        });
    }

    public static function layerAverage($topImage, $bottomImage)
    {
        //(A + B) / 2
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return $A + $B / 2;
        });
    }

    public static function layerAdd($topImage, $bottomImage)
    {
        //min(255, (A + B))
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return min(255, ($A + $B));
        });
    }

    public static function layerSubtract($topImage, $bottomImage)
    {
        //(A + B < 255) ? 0:(A + B - 255)
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return $A + $B < 255 ? 0 : $A + $B - 255;
        });
    }

    public static function layerDiference($topImage, $bottomImage)
    {
        //差值
        //abs(A - B)
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return abs($A - $B);
        });
    } 

    public static function layerNegation($topImage, $bottomImage)
    {
        //255 - abs(255 - A - B)
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return abs(255 - $A - $B);
        });
    } 

    public static function layerScreen($topImage, $bottomImage)
    {
        //滤色
        //255 - (((255 - A) * (255 - B)) >> 8))
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return 255 - ( ((255 - $A) * (255 - $B)) >> 8);
        });
    }

    public static function layerExclusion($topImage, $bottomImage)
    {
        //排除
        //A + B - 2 * A * B / 255
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return $A + $B - 2 * $A * $B / 255;
        });
    }

    public static function layerOverlay($topImage, $bottomImage)
    {
        //叠加
        //(B < 128) ? (2 * A * B / 255):(255 - 2 * (255 - A) * (255 - B) / 255)
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return ($B < 128) ? (2 * $A * $B / 255) : (255 - 2 * (255 - $A) * (255 - $B) / 255);
        });
    }

    public static function layerSoftLight($topImage, $bottomImage)
    {
        //柔光
        //(B < 128)?(2*((A>>1)+64))*((float)B/255):(255-(2*(255-((A>>1)+64))*(float)(255-B)/255))
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return $B < 128 ? 
             (2 * (( $A >> 1) + 64)) * ($B / 255) : 
             (255 - ( 2 * (255 - ( ($A >> 1) + 64 ) )  *  ( 255 - $B ) / 255 ));
        });
    }

    public static function layerHardLight($topImage, $bottomImage)
    {
        //强光
        //Overlay(B,A)
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return ($A < 128) ? (2 * $A * $B / 255) : (255 - 2 * (255 - $A) * (255 - $B) / 255);
        });
    }

    public static function layerColorDodge($topImage, $bottomImage)
    {
        //颜色减淡
        //(B == 255) ? B:min(255, ((A << 8 ) / (255 - B)))
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return $B == 255 ? $B : min(255, (($A << 8 ) / (255 - $B)));
        });
    }

    public static function layerColorBurn($topImage, $bottomImage)
    {
        //颜色加深
        //(B == 0) ? B:max(0, (255 - ((255 - A) << 8 ) / B)))
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return $B == 0 ? $B : max(0, (255 - ((255 - $A) << 8 ) / $B));
        });
    }

    public static function layerLinearDodge($topImage, $bottomImage)
    {
        //线性减淡
        self::layerAdd($topImage, $bottomImage);
    }


    public static function layerLinearBurn($topImage, $bottomImage)
    {
        //线性加深
        self::layerSubtract($topImage, $bottomImage);
    }

    public static function layerLinearLight($topImage, $bottomImage)
    {
        //线性光
        //(B < 128) ? LinearBurn(A,(2 * B)) : LinearDodge(A,(2 * (B - 128)))
        
        //min(255, (A + B))
        //(A + B < 255) ? 0:(A + B - 255)
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return $B < 128 ? 
                min(255, ($A + 2 * $B)) :
                $A + (2 * ($B - 128)) < 255 ? 0 : $A + (2 * ($B - 128)) - 255
            ;
        });
    }

    public static function layerVividLight($topImage, $bottomImage)
    {
        //亮光
        //B < 128 ? ColorBurn(A,(2 * B)) : ColorDodge(A,(2 * (B - 128)))
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return $B < 128 ? 
            (
                $B == 0 ? 2 * $B : max(0, (255 - ((255 - $A) << 8 ) / (2 * $B)))
            ) :
            (
                (2 * ($B - 128)) == 255 ? (2 * ($B - 128)) : min(255, (($A << 8 ) / (255 - (2 * ($B - 128)) )))
            ) ;
        });
    }

    public static function layerPinLight($topImage, $bottomImage)
    {
        //点光
        //(B < 128) ? Darken(A,(2 * B)) : Lighten(A,(2 * (B - 128))
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return $B < 128 ? 
            (
                2 * $B > $A ? $A : 2 * $B
            ) :
            (
                (2 * ($B - 128)) > $A ? (2 * ($B - 128)) : $A
            ) ;
        });
    }

    public static function layerHardMix($topImage, $bottomImage)
    {
        //实色混合
        //(VividLight(A,B) < 128) ? 0:255)
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return ($B < 128 ? 
            (
                $B == 0 ? 2 * $B : max(0, (255 - ((255 - $A) << 8 ) / (2 * $B)))
            ) :
            (
                (2 * ($B - 128)) == 255 ? (2 * ($B - 128)) : min(255, (($A << 8 ) / (255 - (2 * ($B - 128)) )))
            ))
            < 128 ? 0 : 255 ;
        });
    }

    public static function layerReflect($topImage, $bottomImage)
    {
        //(B == 255) ? B : min(255, (A * A / (255 - B)))
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return $B == 255 ? $B : min(255, ($A * $A / (255 - $B)));
        });
    }

    public static function layerPhoenix($topImage, $bottomImage)
    {
        //min(A,B) - max(A,B) + 255
        self::pixelHandler($topImage, $bottomImage, function($A, $B) {
            return abs(min($A, $B) - max($A, $B) - 255);
        });
    }

}
