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

        return $this->thumber = new Gd\Imagine();
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
        $params = new Parameters();
        $params->fromString($url->getUrlImageName());
        p($params);
    }

    public function show()
    {
    }
}
