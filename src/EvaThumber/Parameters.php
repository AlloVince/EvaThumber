<?php
namespace EvaThumber;

use ArrayObject;

class Parameters
{
    protected $border;
    protected $dummy;
    protected $crop;
    protected $filter;
    protected $gravity;
    protected $height;
    protected $width;
    protected $percent;
    protected $quality;
    protected $rotate;
    protected $x;
    protected $y;
    protected $extension;
    protected $filename;

    protected $imageWidth;
    protected $imageHeight;

    protected $argMapping = array(
        'b' => 'border',
        'c' => 'crop',
        'd' => 'dummy',
        'f' => 'filter',
        'g' => 'gravity',
        'h' => 'height',
        'p' => 'percent',
        'q' => 'quality',
        'r' => 'rotate',
        'w' => 'width',
        'x' => 'x',
        'y' => 'y',
    );

    protected $argDefaults = array(
        'border' => null,
        'crop' => 'crop',
        'dummy' => null, //picasa | flickr
        'filter' => null,
        'gravity' => null,
        'height' => null,
        'percent' => 100,
        'quality' => 100,
        'rotate' => 360,
        'width' => null,
        'x' => null,
        'y' => null,
    );

    protected $config;

    public function setCrop($crop)
    {
        $crops = array('crop', 'fill');
        if(is_numeric($crop)){
            $crop = (int) $crop; 
        } elseif(is_string($crop)){
            $crop = strtolower($crop);
        }
        $this->crop = $crop;
        return $this;
    }

    public function getCrop()
    {
        if($this->crop){
            return $this->crop;
        }

        return $this->crop = $this->argDefaults['crop'];
    }

    public function setBorder($border)
    {
        $this->border = $border;
        return $this;
    }

    public function getBorder()
    {
        return $this->border;
    }

    public function setDummy($dummy)
    {
        $dummy = strtolower($dummy);
        if(false === in_array($dummy, array('flickr', 'picasa'))){
            $this->dummy = null;
            return $this;
        }
        $this->dummy = $dummy;
        return $this;
    }

    public function getDummy()
    {
        return $this->dummy;
    }

    public function setFilter($filter)
    {
        $filter = strtolower($filter);
        if(false === in_array($filter, array('gray', 'negative', 'gamma'))){
            $this->filter = null;
            return $this;
        }
        $this->filter = $filter;
        return $this;
    }

    public function getFilter()
    {
        return $this->filter;
    }

    public function getGravity()
    {
        return $this->gravity;
    }

    public function setGravity($gravity)
    {
        $this->gravity = (int) $gravity;
        return $this;
    }

    public function setPercent($percent)
    {
        $percent = (int) $percent;
        $percent = $percent > 100 ? 100 : $percent;
        $percent = $percent < 1 ? 1 : $percent;
        $this->percent = $percent;
        return $this;
    }

    public function getPercent()
    {
        return $this->percent;
    }

    public function getQuality()
    {
        if($this->quality){
            return $this->quality;
        }

        return $this->quality = $this->argDefaults['quality'];
    }

    public function setQuality($quality)
    {
        $extension = $this->getExtension();
        if(false === in_array($extension, array('jpg', 'jpeg'))){
            $this->quality = null;
            return $this;
        }
        $this->quality = (int) $quality;
        return $this;
    }

    public function getWidth()
    {
        return $this->width;
    }

    public function setWidth($width)
    {
        $width = (int) $width;
        /*
        if(!$this->config->allow_stretch){
            $maxWidth = $this->argDefaults['width'];
            $width = $maxWidth && $width > $maxWidth ? $maxWidth : $width;
        }
        */
        $this->width = $width;
        return $this;
    }

    public function getHeight()
    {
        return $this->height;
    }

    public function setHeight($height)
    {
        $this->height = (int) $height;
        return $this;
    }

    public function getX()
    {
        return $this->x;
    }

    public function setX($x)
    {
        $this->x = (int) $x;
        return $this;
    }

    public function getY()
    {
        return $this->y;
    }

    public function setY($y)
    {
        $this->y = (int) $y;
        return $this;
    }

    public function getRotate()
    {
        return $this->rotate;
    }

    public function setRotate($rotate)
    {
        $rotate = (int) $rotate;

        //rotate is between 1 ~ 360
        $this->rotate = $rotate % 360;
        return $this;
    }

    public function getExtension()
    {
        if(!$this->extension){
            throw new Exception\InvalidArgumentException(sprintf('File extension not be set in parameters'));
        }
        return $this->extension;
    }

    public function setExtension($extension)
    {
        $this->extension = strtolower($extension);
        return $this;    
    }

    public function getFilename()
    {
        if(!$this->filename){
            throw new Exception\InvalidArgumentException(sprintf('Filename not be set in parameters'));
        }
        return $this->filename;
    }

    public function setFilename($filename)
    {
        $this->filename = $filename;
        return $this;
    }


    public function setConfig(Config\Config $config)
    {
        $this->config = $config;
        return $this;
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function setImageSize($imageWidth, $imageHeight)
    {
        $this->imageWidth = $imageWidth;
        $this->imageHeight = $imageHeight;
        $this->normalize();
        return $this;
    }

    /**
    * Populate from native PHP array
    *
    * @param  array $values
    * @return void
    */
    public function fromArray(array $params)
    {
        foreach($params as $key => $value){
            $method = 'set' . ucfirst($key);
            if(method_exists($this, $method)){
                $this->$method($value);
            }
        }
        $this->normalize();
        return $this;
    }

    /**
    * Populate from filename string
    *
    * @param  string $string
    * @return void
    */
    public function fromString($fileName)
    {
        $fileNameArray = $fileName ? explode('.', $fileName) : array();
        if(!$fileNameArray || count($fileNameArray) < 2){
            throw new Exception\InvalidArgumentException('File name not correct');
        }

        $fileExt = array_pop($fileNameArray);
        $fileNameMain = implode('.', $fileNameArray);
        $fileNameArray = explode(',', $fileNameMain);
        if(!$fileExt || !$fileNameArray || !$fileNameArray[0]){
            throw new Exception\InvalidArgumentException('File name not correct');
        }

        //remove empty elements
        $fileNameArray = array_filter($fileNameArray);
        $fileNameMain = array_shift($fileNameArray);
        $this->setExtension($fileExt);
        $this->setFilename($fileNameMain);

        $args = $fileNameArray;
        $argMapping = $this->argMapping;
        $params = array();
        foreach($args as $arg){
            if(!$arg){
                continue;
            }
            if(strlen($arg) < 3 || strpos($arg, '_') !== 1){
                continue;
            }
            $argKey = $arg{0};
            if(isset($argMapping[$argKey])){
                $arg = substr($arg, 2);
                if($arg !== ''){
                    $params[$argMapping[$argKey]] = $arg;
                }
            }
        }

        $this->fromArray($params);
        return $params;
    }

    /**
    * Serialize to native PHP array
    *
    * @return array
    */
    public function toArray()
    {
        return array(
            'filter' => $this->getFilter(),
            'width' => $this->getWidth(),
            'height' => $this->getHeight(),
            'percent' => $this->getPercent(),
            'dummy' => $this->getDummy(),
            'border' => $this->getBorder(),
            'quality' => $this->getQuality(), 
            'crop' => $this->getCrop(),
            'x' => $this->getX(),
            'y' => $this->getY(),
            'rotate' => $this->getRotate(),
            'gravity' => $this->getGravity(), 
            'extension' => $this->getExtension(),
            'filename' => $this->getFilename(),
        );
    }

    /**
    * Serialize to query string
    *
    * @return string
    */
    public function toString()
    {
        $params = $this->toArray();
        $filename = $params['filename'];
        $extension = $params['extension'];
        unset($params['filename'], $params['extension']);

        ksort($params);
        $mapping = array_flip($this->argMapping);
        $defaults = $this->argDefaults;

        $nameArray = array();
        foreach($params as $key => $value){
            //remove value if as same as default setting
            if($value !== null && $value !== $defaults[$key]){
                $nameArray[$mapping[$key]] = $mapping[$key] . '_' . $value;
            }
        }
        $nameArray = $nameArray ? ',' . implode(',', $nameArray) : '';
        return $filename . $nameArray . '.' . $extension;
    }

    /**
    * Constructor
    *
    * Enforces that we have an array, and enforces parameter access to array
    * elements.
    *
    * @param  array $values
    */
    public function __construct($imageName = null, Config\Config $config = null)
    {
        if($imageName && is_string($imageName)){
            $this->fromString($imageName);
        }

        if($imageName && is_array($imageName)){
            $this->fromArray($imageName);
        }
    }

    protected function normalize()
    {
        //set default here;
        $defaults = $this->argDefaults;
        $config = $this->getConfig();


        //Max width & height from config
        if($config){
            $maxWidth = $config->max_width;
            $maxHeight = $config->max_height;
            if($maxWidth){
                $defaults['width'] = $maxWidth;
            }
            if($maxHeight){
                $defaults['height'] = $maxHeight;
            }

            //Change max width & height as image size if small than config
            $imageWidth = $this->imageWidth;
            $imageHeight = $this->imageHeight;
            $allowStretch = $config->allow_stretch;
            if($imageWidth && $imageHeight){
                if($maxWidth && $maxWidth < $imageWidth){
                    $defaults['width'] = $maxWidth;
                } else {
                    $maxWidth = $allowStretch ? $maxWidth : $imageWidth;
                    $defaults['width'] = $maxWidth;
                }

                if($maxHeight && $maxHeight < $imageHeight){
                    $defaults['height'] = $maxHeight;
                } else {
                    $maxHeight = $allowStretch ? $maxHeight : $imageHeight;
                    $defaults['height'] = $maxHeight;
                }
            }

            //Width & height Limit
            $width = $this->width;
            $height = $this->height;
            if($width && $maxWidth){
                $this->width = $width > $maxWidth ? $maxWidth : $width;
            }
            if($height && $maxHeight){
                $this->height = $height > $maxHeight ? $maxHeight : $height;
            }

            if($config->allow_sizes){

            }

            if($config->quality){
                $defaults['quality'] = $config->quality;
            }
        }

        //X & Y only need when cropping
        if(!$this->crop || $this->crop == 'fill'){
            $this->x = null;
            $this->y = null;
        }

        //fill mode request both width & height
        if($this->crop == 'fill' & (!$this->width || !$this->height)){
            $defaults['crop'] = 'fill';
        }

        if(is_numeric($this->crop)){
            if($this->x === null || $this->y === null){
                $this->x = null;
                $this->y = null;
            }
        }

        if($this->percent){
            $this->width = null;
            $this->height = null;
        }

        $this->argDefaults = $defaults;

        return $this;
    }
}
