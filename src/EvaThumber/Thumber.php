<?php
/**
 * EvaThumber
 * light-weight url based image transformation php library
 *
 * @link      https://github.com/AlloVince/EvaCloudImage
 * @copyright Copyright (c) 2013 AlloVince (http://avnpc.com/)
 * @license   New BSD License
 * @author    AlloVince
 */

namespace EvaThumber;

use Imagine;
use Imagine\Image\ImagineInterface;

class Thumber
{
    /**
     * @var Config
     */
    protected $config;

    protected $image;

    protected $thumber;

    protected $url;

    protected $params;

    protected $filesystem;

    protected $sourcefile;

    public function getThumber($sourcefile = null, $adapter = null)
    {
        if($this->thumber){
            return $this->thumber;
        }

        $adapter = $adapter ? $adapter : strtolower($this->config->adapter);
        switch ($adapter) {
            case 'gd':
            $thumber = new Imagine\Gd\Imagine();
            break;
            case 'imagick':
            $thumber = new Imagine\Imagick\Imagine();
            break;
            case 'gmagick':
            $thumber = new Imagine\Gmagick\Imagine();
            break;
            default:
            $thumber = new Imagine\Gd\Imagine();
        }

        if($sourcefile){
            $this->image = $thumber->open($sourcefile);
        }
        return $this->thumber = $thumber;
    }

    public function setThumber(ImagineInterface $thumber)
    {
        $this->thumber = $thumber;
        return $this;
    }

    public function getImage()
    {
        return $this->image;
    }

    /**
     * Set configuration object
     *
     * @param  Config $config
     * @return AbstractManager
     */
    public function setConfig(Config\ConfigInterface $config)
    {
        $this->config = $config;
        return $this;
    }

    /**
     * Retrieve configuration object
     *
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    public function getFilesystem()
    {
        if($this->filesystem){
            return $this->filesystem;
        }
        return $this->filesystem = new Filesystem();
    }

    public function getSourcefile()
    {
        if($this->sourcefile){
            return $this->sourcefile;
        }
        $fileRootPath = $this->config->source_path;
        $filePath = $this->url->getImagePath();
        $fileName = $this->url->getImageName();

        if(!$fileName){
            throw new Exception\InvalidArgumentException(sprintf("Request an empty filename"));
        }

        $sourcefile = $fileRootPath . $filePath . '/' . $fileName;

        if(false === $this->getFilesystem()->exists($sourcefile)){
            throw new Exception\IOException(sprintf(
                "Request file not find in %s", $sourcefile
            ));
        }

        return $this->sourcefile = $sourcefile;
    }

    public function getParameters()
    {
        if($this->params){
            return $this->params;
        }

        $params = new Parameters();
        $params->setConfig($this->config);
        $params->fromString($this->url->getUrlImageName());
        return $this->params = $params;
    }

    public function __construct($config, $url = null)
    {
        if($config instanceof Config\Config){
            $this->config = $config; 
        } else {
            $this->config = new Config\Config($config);
        }
        $this->url = $url = new Url($url);
        $configKey = $url->getUrlKey();
        if(isset($this->config->thumbers->$configKey)){
            $this->config = $config = $this->config->thumbers->$configKey;
        }
        
        /*
        p($config);
        p($url->toArray());
        p($params->toString());
        p($params->toArray());
        */
    }

    protected function transform()
    {
        $params = $this->getParameters();
        $size    = new Imagine\Image\Box(100, 100);
        //$mode    = Imagine\Image\ImageInterface::THUMBNAIL_INSET;
        $mode    = Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND;

        $transformation = new Imagine\Filter\Advanced\Grayscale();
        //$transformation = new Imagine\Filter\Advanced\Border(new Imagine\Image\Color('000', 100));
        $image = $transformation->apply($image);
        return $this;
    }

    protected function crop()
    {
        $params = $this->getParameters();
        $width = $params->getWidth();
        $height = $params->getHeight();
        return $this;
    }

    protected function resize()
    {
        $params = $this->getParameters();
        $width = $params->getWidth();
        $height = $params->getHeight();

        return $this;
    }

    protected function rotate()
    {
        $params = $this->getParameters();
        return $this;
    }

    protected function filter()
    {
        $params = $this->getParameters();
        return $this;
    }

    protected function quality()
    {
        return $this;
    }

    public function show()
    {
        $sourcefile = $this->getSourcefile();
        $thumber = $this->getThumber($sourcefile);

        $this->resize()
        ->rotate()
        ->filter()
        ->quality();
        $params = $this->getParameters();
        $extension = $params->getExtension();
        $image = $this->getImage();
        return $image->show($extension);
    }
}
