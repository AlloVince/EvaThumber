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

/**
 * Effects implementation using the GD library
 */
class Effects extends \Imagine\Gd\Effects
{
   protected $resource;

   public function contrast($contrast = 10)
   {
      //In GD contrast is between -100 max ~ min will be turn to -50 min ~ 50 max
      $contrast = -$contrast * 2;
      if (false === imagefilter($this->resource, IMG_FILTER_CONTRAST, $contrast)) {
         throw new RuntimeException('Failed to contrast the image');
      }
      return $this;
   }

   public function brightness($brightness = 10)
   {
      //In GD brightness is between -255 ~ 255 will be turn to -50 ~ 50
      $brightness = $brightness * 51 / 10;
      if (false === imagefilter($this->resource, IMG_FILTER_BRIGHTNESS, $brightness)) {
         throw new RuntimeException('Failed to brightness the image');
      }
      return $this;
   }

   public function mosaic($blockSize = 6, $advanced = true)
   {
      if (false === imagefilter($this->resource, IMG_FILTER_PIXELATE, $blockSize, $advanced)) {
         throw new RuntimeException('Failed to mosaic the image');
      }
      return $this;
   }


   public function emboss()
   {
        if (false === imagefilter($this->resource, IMG_FILTER_EMBOSS)) {
           throw new RuntimeException('Failed to emboss the image');
        }
        return $this;
   }

   public function borderline()
   {
        if (false === imagefilter($this->resource, IMG_FILTER_EDGEDETECT)) {
           throw new RuntimeException('Failed to borderline the image');
        }
        return $this;
   }

   public function gaussBlur()
   {
        if (false === imagefilter($this->resource, IMG_FILTER_GAUSSIAN_BLUR)) {
           throw new RuntimeException('Failed to gaussBlur the image');
        }
        return $this;
   }


   public function darkCorner($argLevel = 3, $argType = 'round', $argLastLevel = 30)
   {
   }
}
