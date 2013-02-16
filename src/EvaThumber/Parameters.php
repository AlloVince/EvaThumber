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
        'g' => 'gravity',
        'h' => 'height',
        'q' => 'quality',
        'r' => 'rotate',
        'w' => 'width',
        'x' => 'x',
        'y' => 'y',
    );

    public function setCrop($crop)
    {
        $this->crop = $crop;
        return $this;
    }

    public function getCrop()
    {
        return $this->crop;
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
        return $this->quality;
    }

    public function setQuality($quality)
    {
        $this->quality = $quality;
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
        return $this->extension;
    }

    public function setExtension($extension)
    {
        $this->extension = $extension;
        return $this;    
    }

    public function getFilename()
    {
        return $this->filename;
    }

    public function setFilename($filename)
    {
        $this->filename = $filename;
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
        );
    }

    /**
    * Serialize to query string
    *
    * @return string
    */
    public function toString()
    {
        $array = $this->toArray();
    }

    /**
    * Constructor
    *
    * Enforces that we have an array, and enforces parameter access to array
    * elements.
    *
    * @param  array $values
    */
    public function __construct($imageName = null)
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
        return $this;
    }
}
