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

    protected $fileSystem;

    public function getThumber()
    {
        if($this->thumber){
            return $this->thumber;
        }

        return $this->thumber = new Imagine\Gd\Imagine();
    }

    public function setThumber(ImagineInterface $thumber)
    {
        $this->thumber = $thumber;
        return $this;
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

    public function __construct($config, $url = null)
    {
        if($config instanceof Config\Config){
            $this->config = $config; 
        } else {
            $this->config = new Config\StandardConfig($config);
        }
        $this->url = $url = new Url($url);
        $params = new Parameters();
        $params->fromString($url->getUrlImageName());
        $this->params = $params;
        
        p($this->config->thumbers->default);
        p($url->toArray());
        p($params->toString());
        p($params->toArray());
    }

    protected function transform()
    {
    }

    protected function resize()
    {
    
    }

    protected function rotate()
    {
    }

    protected function filter()
    {
    }

    public function show()
    {

        return;
        $thumber = $this->getThumber();
        $image = $thumber->open(__DIR__ . '/../../upload/demo.jpg');
        $size    = new Imagine\Image\Box(400, 300);
        //$mode    = Imagine\Image\ImageInterface::THUMBNAIL_INSET;
        $mode    = Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND;

        //$transformation = new Imagine\Filter\Advanced\Grayscale();
        //$transformation = new Imagine\Filter\Advanced\Border(new Imagine\Image\Color('000', 100));
        //$transformation->apply($image)->show('png');

        //$image->thumbnail($size, $mode)->show('png');
        $image->show('png');

    }
}
