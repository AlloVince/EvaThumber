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

    /**
    * @var Imagine\Image\Image
    */
    protected $image;

    protected $imageOptions = array();

    /**
    * @var Imagine\Image\ImagineInterface
    */
    protected $thumber;

    /**
    * @var Url
    */
    protected $url;

    /**
    * @var Parameters
    */
    protected $params;

    protected $filesystem;

    protected $sourcefile;

    protected $faker;

    protected $cacher;

    public function getCacher()
    {
        if($this->cacher){
            return $this->cacher;
        }
        return $this->cacher = new Cacher();
    }

    public function setCacher(Cacher $cacher)
    {
        $this->cacher = $cacher;
        return $this;
    }

    public function getThumber($sourcefile = null, $adapter = null)
    {
        if($this->thumber){
            return $this->thumber;
        }

        $thumber = $this->createThumber($adapter);

        if($sourcefile){
            $this->image = $thumber->open($sourcefile);
        }
        return $this->thumber = $thumber;
    }

    public function getFaker($dummyName)
    {
        if($this->faker) {
            return $this->faker;
        }

        return $this->faker = new Faker($dummyName);
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

    public function getUrl()
    {
        return $this->url;
    }

    public function getImageOptions()
    {
        return $this->imageOptions;
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
        return $this->sourcefile = $sourcefile;
    }

    public function setSourcefile($sourcefile)
    {
        $this->sourcefile = $sourcefile;
        return $this;
    }

    public function sourcefileExsit()
    {
        $sourcefile = $this->getSourcefile();
        $sourcefilePath = substr($sourcefile, 0, strrpos($sourcefile, '.'));
        $fileExist = false;

        //Not use file system, instead of glob
        foreach (glob($sourcefilePath . '.*') as $sourcefile) {
            $this->setSourcefile($sourcefile);
            $fileExist = true;
            break;
        }

        /*
        $filesystem = $this->getFilesystem();
        foreach (glob($sourcefilePath . '.*') as $sourcefile) {
            if(true === $filesystem->exists($sourcefile)){
                $this->setSourcefile($sourcefile);
                $fileExist = true;
                break;
            }
        }
        */
        return $fileExist;
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

    public function setParameters(Parameters $params)
    {
        $this->params = $params;
        return $this;
    }

    public function redirect($imageName)
    {
        $config = $this->getConfig();
        $this->getUrl()->setUrlImageName($imageName);
        $newUrl = $this->getUrl()->toString();
        return header("location:$newUrl"); //+old url + server referer
    }


    protected function process()
    {
        $config = $this->getConfig();
        $params = $this->getParameters();
        $params->disableOperates($config->disable_operates);
        $url = $this->getUrl();
        $urlImageName = $url->getUrlImageName();
        $newImageName = $params->toString();

        //Keep unique url
        if($urlImageName !== $newImageName){
            return $this->redirect($newImageName);
        }


        //Dummy file will replace source file
        $dummy = $params->getDummy();
        if($this->sourcefileExsit()){
            if($dummy){
                throw new Exception\IOException(sprintf(
                    "Dummy file name conflict with exsit file %s", $sourcefile
                ));
            }
        } else {
            if(!$dummy){
                throw new Exception\IOException(sprintf(
                    "Request file not find in %s", $this->getSourcefile()
                ));
            }
        }
        if($dummy){
            $faker = $this->getFaker($dummy);
            $sourcefile = $faker->getFile();
        } else {
            $sourcefile = $this->getSourcefile();
        }

        //Start reading file
        $thumber = $this->getThumber($sourcefile);
        $params->setImageSize($this->getImage()->getSize()->getWidth(), $this->getImage()->getSize()->getHeight());
        $newImageName = $params->toString();

        //Keep unique url again when got image width & height
        if($urlImageName !== $newImageName){
            return $this->redirect($newImageName);
        }
        
        $this
            ->crop()  //crop first then resize
            ->resize()
            ->rotate()
            ->filter()
            ->layer() 
            ->quality();

        return $this;
    }


    public function save()
    {
    }

    public function show()
    {
        $config = $this->getConfig();
        $extension = $this->getParameters()->getExtension();
        
        $this->process();
        $image = $this->getImage();

        if($config->cache){
            $cacheRoot = $config->thumb_cache_path;
            $imagePath = '/' . $this->getUrl()->getUrlKey() . $this->getUrl()->getImagePath();
            $cachePath = $cacheRoot . $imagePath . '/' . $this->getUrl()->getUrlImageName();
            $pathLevel = count(explode('/', $imagePath));
            $this->getFilesystem()->prepareDirectoryStructure($cachePath, $pathLevel);
            $image->save($cachePath, $this->getImageOptions());
        }

        return $image->show($extension, $this->getImageOptions());
    }

    public function __construct($config, $url = null)
    {
        if($config instanceof Config\Config){
            $config = $config; 
        } else {
            $config = new Config\Config($config);
        }
        $this->url = $url = new Url($url);
        $configKey = $url->getUrlKey();
        $defaultConfig = $config->thumbers->current();
        $defaultKey = $config->thumbers->key();
        if(isset($config->thumbers->$configKey)){
            if($defaultKey == $configKey){
                $this->config = $config->thumbers->$configKey;
            } else {
                $this->config = $defaultConfig->merge($config->thumbers->$configKey);
            }
        } else {
            throw new Exception\InvalidArgumentException(sprintf(
                'No config found by key %s', $configKey
            ));
        }
    }

    protected function createThumber($adapter = null)
    {
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
        return $thumber;
    }

    protected function createFont($font, $size, $color)
    {
        $thumberClass = get_class($this->getThumber());
        $classPart = explode('\\', $thumberClass);
        $classPart[2] = 'Font';
        $fontClass = implode('\\', $classPart);
        return new $fontClass($font, $size, $color);
    }


    protected function crop()
    {
        $params = $this->getParameters();
        $crop = $params->getCrop();
        if(!$crop){
            return $this;
        }

        if(false === is_numeric($crop)){
            return $this;
        }
        $gravity = $params->getGravity();
        $gravity = $gravity ? $gravity : $crop;

        $x = $params->getX();
        $y = $params->getY();

        $image = $this->getImage();
        $imageWidth = $image->getSize()->getWidth();
        $imageHeight = $image->getSize()->getHeight();

        $x = $x !== null ? $x : ($imageWidth - $crop) / 2;
        $y = $y !== null ? $y : ($imageHeight - $gravity) / 2;

        $this->image = $image->crop(new Imagine\Image\Point($x, $y), new Imagine\Image\Box($crop, $gravity));
        return $this;
    }

    protected function resize()
    {
        $params = $this->getParameters();
        $percent = $this->params->getPercent();

        if($percent) {
            $this->resizeByPercent();
        } else {
            $this->resizeBySize();
        }
        return $this;
    }

    protected function resizeBySize()
    {
        $params = $this->getParameters();

        $width = $params->getWidth();
        $height = $params->getHeight();
        $maxWidth = $this->config->max_width;
        $maxHeight = $this->config->max_height;

        $image = $this->getImage();
        $imageWidth = $image->getSize()->getWidth();
        $imageHeight = $image->getSize()->getHeight();

        //No size input, require size limit from config
        if(!$width && !$height){
            if(!$maxWidth && !$maxHeight){
                return $this;
            }

            if($maxWidth && $imageWidth > $maxWidth || $maxHeight && $imageHeight > $maxHeight){
                $width = $maxWidth && $imageWidth > $maxWidth ? $maxWidth : $width;
                $height = $maxHeight && $imageHeight > $maxHeight ? $maxHeight : $height;

                //If only width or height, resize by image size radio
                $width = $width ? $width : ceil($height * $imageWidth / $imageHeight);
                $height = $height ? $height : ceil($width * $imageHeight / $imageWidth);
            } else {
                return $this;
            }

        } else {
            if($width === $imageWidth || $height === $imageHeight){
                return $this;
            }

            //If only width or height, resize by image size radio
            $width = $width ? $width : ceil($height * $imageWidth / $imageHeight);
            $height = $height ? $height : ceil($width * $imageHeight / $imageWidth);

            $allowStretch = $this->config->allow_stretch;

            if(!$allowStretch){
                $width = $width > $maxWidth ? $maxWidth : $width;
                $width = $width > $imageWidth ? $imageWidth : $width;
                $height = $height > $maxHeight ? $maxHeight : $height;
                $height = $height > $imageHeight ? $imageHeight : $height;
            }
        }

        $size    = new Imagine\Image\Box($width, $height);
        $crop = $params->getCrop();
        if($crop === 'fill'){
            $mode    = Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND;
        } else {
            $mode    = Imagine\Image\ImageInterface::THUMBNAIL_INSET;
        }
        $this->image = $image->thumbnail($size, $mode);
        return $this;
    }

    protected function resizeByPercent()
    {
        $params = $this->getParameters();
        $percent = $this->params->getPercent();

        if(!$percent || $percent == 100){
            return $this;
        }

        $image = $this->getImage();
        $imageWidth = $image->getSize()->getWidth();
        $imageHeight = $image->getSize()->getHeight();

        $box =  new Imagine\Image\Box($imageWidth, $imageHeight);
        $mode    = Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND;
        $box = $box->scale($percent / 100);
        $this->image = $image->thumbnail($box, $mode);
        return $this;
    }


    protected function rotate()
    {
        $rotate = $this->getParameters()->getRotate();
        if($rotate){
            $image = $this->getImage();
            $image->rotate($rotate);
        }
        return $this;
    }

    protected function filter()
    {
        $filter = $this->getParameters()->getFilter();
        if(!$filter){
            return $this;
        }

        switch($filter){
            case 'gray':
            $this->getImage()->effects()->grayscale();
            break;
            case 'gamma':
            $this->getImage()->effects()->gamma(0.7);
            break;
            case 'negative':
            $this->getImage()->effects()->negative();
            break;
            default:
        }
        return $this;
    }

    protected function quality()
    {
        $quality = $this->getParameters()->getQuality();
        if($quality){
            $this->imageOptions['quality'] = $quality;
        }
        return $this;
    }

    protected function border()
    {
        return $this;
    }

    protected function layer()
    {
        $config = $this->config->watermark;
        if(!$config || !$config->enable){
            return $this;
        }

        $textLayer = false;
        $text = $config->text;
        if($config->layer_file){
            $waterLayer = $this->createThumber()->open($config->layer_file);
            $layerWidth = $waterLayer->getSize()->getWidth();
            $layerHeight = $waterLayer->getSize()->getHeight();
        } else {
            if(!$text || !$config->font_file || !$config->font_size || !$config->font_color){
                return $this;
            }
            $font = $this->createFont($config->font_file, $config->font_size, new Imagine\Image\Color($config->font_color));
            $layerBox = $font->box($text);
            $layerWidth = $layerBox->getWidth();
            $layerHeight = $layerBox->getHeight();
            $textLayer = true;
        }

        $image = $this->getImage();
        $imageWidth = $image->getSize()->getWidth();
        $imageHeight = $image->getSize()->getHeight();

        $x = 0;
        $y = 0;
        $position = $config->position;
        switch($position){
            case 'tl':
            break;

            case 'tr':
            $x = $imageWidth - $layerWidth;
            break;

            case 'bl':
            $y = $imageHeight - $layerHeight;
            break;

            case 'center':
            $x = ($imageWidth - $layerWidth) / 2;
            $y = ($imageHeight - $layerHeight) / 2;
            break;

            case 'br':
            default:
            $x = $imageWidth - $layerWidth;
            $y = $imageHeight - $layerHeight;
        }
        $point = new Imagine\Image\Point($x, $y);

        if($textLayer){
            $this->getImage()->draw()->text($text, $font, $point);
        } else {
            $this->image = $this->getImage()->paste($waterLayer, $point);
        }

        return $this;
    }


}
