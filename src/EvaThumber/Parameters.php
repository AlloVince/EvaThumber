<?php
namespace EvaThumber;

use ArrayObject;

class Parameters
{
    protected $crop;
    protected $gravity;
    protected $height;
    protected $width;
    protected $quality;
    protected $rotate;
    protected $x;
    protected $y;
    protected $percent;
    protected $extension;
    protected $filename;

    protected $argMapping = array(
        'c' => 'crop',
        'd' => 'dummy',
        'g' => 'gravity',
        'h' => 'height',
        'q' => 'quality',
        'r' => 'rotate',
        'w' => 'width',
        'x' => 'x',
        'y' => 'y',
    );

    protected $argDefaults = array(
        'crop' => 'crop',
        'd' => null, //picasa | flickr
        'gravity' => null,
        'height' => null,
        'quality' => 100,
        'rotate' => null,
        'width' => null,
        'x' => null,
        'y' => null,
    );

    protected $config;

    protected $normalized = false;

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

    public function getGravity()
    {
        return $this->gravity;
    }

    public function setGravity($gravity)
    {
        $this->gravity = $gravity;
        return $this;
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
        $this->width = (int) $width;
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
        $this->rotate = $rotate;
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
        $this->nomalrize();
        return $this;
    }

    public function getConfig()
    {
        return $this->config;
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
        return $this;
    }

    /**
    * Populate from query string
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
            if(strpos($arg, '_') !== 1){
                continue;
            }
            $argKey = $arg{0};
            if(isset($argMapping[$argKey])){
                if($arg = substr($arg, 2)){
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
        $this->nomalrize();
        return array(
            'width' => $this->getWidth(),
            'height' => $this->getHeight(),
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

    protected function nomalrize()
    {
        //set default here;
        $defaults = $this->argDefaults;
        $config = $this->getConfig();

        if($config->max_width){
            $defaults['width'] = $config->max_width;
        }

        if($config->max_height){
            $defaults['height'] = $config->max_height;
        }

        if($config->quality){
            $defaults['quality'] = $config->quality;
        }

        //X & Y only need when cropping
        if(!$this->crop){
            $this->x = null;
            $this->y = null;
        }

        $this->argDefaults = $defaults;

        return $this;
    }
}
