<?php
/**
 * EvaCloudImage
 * light-weight url based image transformation php library
 *
 * @link      https://github.com/AlloVince/EvaCloudImage
 * @copyright Copyright (c) 2012 AlloVince (http://avnpc.com/)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
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

    /**
     * Constructor
     *
     * @param  Config|null $config
     * @param  Storage|null $storage
     * @param  SaveHandler|null $saveHandler
     * @throws Exception\RuntimeException
     */
    public function __construct(array $config = null, $url = null)
    {
        $this->config = new Config\StandardConfig($config);
        $this->url = $url = new Url($url);
        p($url->toArray());
        $params = new Parameters();
        $params->fromString($url->getUrlImageName());
        $this->params = $params;
        //p($params->toString());
        //p($params->toArray());
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
        $size    = new Imagine\Image\Box(40, 40);
        //$mode    = Imagine\Image\ImageInterface::THUMBNAIL_INSET;
        $mode    = Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND;
        $image->thumbnail($size, $mode)
                ->show('png');

    }
}
