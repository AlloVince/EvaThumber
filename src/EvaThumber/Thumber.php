<?php
/**
 * EvaThumber
 * URL based image transformation php library
 *
 * @link      https://github.com/AlloVince/EvaThumber
 * @copyright Copyright (c) 2012-2013 AlloVince (http://avnpc.com/)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 * @author    AlloVince
 */

namespace EvaThumber;

use Imagine;
use Imagine\Image\ImagineInterface;
use Imagine\Image\Box;
use Imagine\Image\Color;
use Imagine\Image\Point;

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

    /**
    * @var array
    */
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

    /**
    * @var mixed
    */
    protected $sourcefile;

    protected $faker;

    protected $cacher;

    protected $processed = false;
    protected $optimized = false;
    protected $optimizedImage;

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

    public function getEffect()
    {
       $className = get_class($this->image);
       return 'EvaThumber\\' . $className($this->image);
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

        if(is_dir($fileRootPath)){

            if(!$fileName){
                throw new Exception\InvalidArgumentException(sprintf("Request an empty filename"));
            }
            $sourcefile = $fileRootPath . $filePath . '/' . $fileName;

        } elseif(is_file($fileRootPath)){

            if(!Feature\ZipReader::isSupport()){
                throw new Exception\BadFunctionCallException(sprintf("Your system not support ZipArchive feature"));
            }

            $sourcefile =  Feature\ZipReader::getStreamPath(urldecode($filePath . '/' . $fileName), $fileRootPath, $this->config->zip_file_encoding);

        } else {

            throw new Exception\IOException(sprintf(
                "Source file not readable %s", $fileRootPath
            ));

        }

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

        if(0 === strpos($sourcefilePath, 'zip://')){

            $files = Feature\ZipReader::glob($sourcefilePath . '.*');
            if($files){
                $streamPath = Feature\ZipReader::parseStreamPath($sourcefile);
                $sourcefile = Feature\ZipReader::getStreamPath($files[0]['name'], $streamPath['zipfile']);
                $this->setSourcefile($sourcefile);
                $fileExist = true;
            }

        } else {
            //Not use file system, instead of glob
            foreach (glob($sourcefilePath . '.*') as $sourcefile) {
                $this->setSourcefile($sourcefile);
                $fileExist = true;
                break;
            }
        }
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

    public function save($path = null)
    {
        return $this->saveImage($path);
    }

    public function show()
    {
        $config = $this->getConfig();
        $extension = $this->getParameters()->getExtension();
        
        $this->process();
        $image = $this->getImage();

        if($config->cache){
            $this->saveImage();
        }

        return $this->showImage($extension);
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

    public function __destruct()
    {
        if($this->optimizedImage){
            unlink($this->optimizedImage);
        }
    }

    protected function saveImage($path = null)
    {
        if(!$path){
            $config = $this->getConfig();
            $cacheRoot = $config->thumb_cache_path;
            $imagePath = '/' . $this->getUrl()->getUrlKey() . $this->getUrl()->getImagePath();
            $cachePath = $cacheRoot . $imagePath . '/' . $this->getUrl()->getUrlImageName();
            $pathLevel = count(explode('/', $imagePath));
            $this->getFilesystem()->prepareDirectoryStructure($cachePath, $pathLevel);
            $path = $cachePath;
        }

        if(true === $this->optimized){
            return $this->saveOptimizedImage($path);
        }

        return $this->saveNormalImage($path);
    }

    protected function saveNormalImage($path = null)
    {
        return $this->getImage()->save($path, $this->getImageOptions());
    }

    protected function saveOptimizedImage($path = null)
    {
        copy($this->optimizedImage, $path);
        return true;
    }

    protected function showImage($extension)
    {
        if(true === $this->optimized){
            return $this->showOptimizedImage($extension);
        }

        return $this->showNormalImage($extension);
    }

    protected function showNormalImage($extension)
    {
        return $this->getImage()->show($extension, $this->getImageOptions());
    }

    protected function showOptimizedImage($extension)
    {
        $mimeTypes = array(
            'jpeg' => 'image/jpeg',
            'jpg'  => 'image/jpeg',
            'gif'  => 'image/gif',
            'png'  => 'image/png',
            'wbmp' => 'image/vnd.wap.wbmp',
            'xbm'  => 'image/xbm',
        );
        header('Content-type: ' . $mimeTypes[$extension]);
        $handle = fopen ($this->optimizedImage, "r");
        echo stream_get_contents($handle);
        unlink($this->optimizedImage);
        fclose($handle);
    }

    protected function process()
    {
        if(true === $this->processed){
            return $this;
        }

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
            ->quality()
            ->optimize();

        $this->processed = true;

        return $this;
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

        $image = $this->getImage();
        if($crop === 'face'){
            if(false === Feature\FaceDetect::isSupport()){
                throw new Exception\BadFunctionCallException(sprintf('No support face detection feature'));
            }

            $feature = new Feature\FaceDetect($this->config->face_detect->bin, $this->config->face_detect->cascade);
            $faceData = $feature->filterDump($this->getImage());

            if(!$faceData || $faceData->faces < 1){
                return $this;
            }

            if($this->config->face_detect->draw_border){
                foreach($faceData->data as $data){
                    $x = $data->x + $data->w / 2;
                    $y = $data->y + $data->h / 2;
                    $image->draw()->ellipse(new Point($x, $y), new Box($data->w, $data->h), new Color('fff'));
                }
            }

            $width = $params->getWidth();
            $height = $params->getHeight();
            if($width && $height){
                $newX = $x - $width / 2 > 0 ? $x - $width / 2 : 0;
                $newY = $y - $height / 2 > 0 ? $y - $height / 2 : 0;
                $this->image = $image->crop(new Imagine\Image\Point($newX, $newY), new Imagine\Image\Box($width, $height));
            
            }
            return $this;
        }

        $gravity = $params->getGravity();
        if(false === is_numeric($crop)){
            return $this;
        }

        $gravity = $gravity ? $gravity : $crop;

        $x = $params->getX();
        $y = $params->getY();

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

        $effects = $this->getImage()->effects('EvaThumber\\' . get_class($this->image) . '\\Effects');
        $blendClass = 'EvaThumber\\' . get_class($this->image) . '\\Blend::';

        switch($filter){
           case 'gray':
           $effects->grayscale();
           break;
           case 'gamma':
           $effects->gamma(0.7);
           break;
           case 'negative':
            $effects->negative();
            break;
            case 'sharp':
            //only in imagine develop version
            $effects->sharpen();
            break;
            case 'lomo':
            $layer = $this->createThumber()->open(__DIR__ . '/../../upload/zz150.jpg');
            $this->getImage()->paste($layer, new Point(0, 0), 100, $blendClass . 'layerOverlay');

            //$drawer->layerNormal();
            //$drawer->layerOverlay();

            //$effects->contrast(10);
            //$effects->brightness(10);
            //$effects->gaussBlur();
            //$effects->mosaic();
            //$effects->borderline();
            //$effects->emboss();
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

            if($config->qr_code && Feature\QRCode::isSupport()){
                $layerFile = Feature\QRCode::generateQRCodeLayer($text, $config->qr_code_size, $config->qr_code_margin);
                $waterLayer = $this->createThumber()->open($layerFile);
                $layerWidth = $waterLayer->getSize()->getWidth();
                $layerHeight = $waterLayer->getSize()->getHeight();
            } else {
                $font = $this->createFont($config->font_file, $config->font_size, new Imagine\Image\Color($config->font_color));
                $layerBox = $font->box($text);
                $layerWidth = $layerBox->getWidth();
                $layerHeight = $layerBox->getHeight();
                $textLayer = true;
            }
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

    protected function optimize()
    {
        $extension = $this->getParameters()->getExtension();
        if($extension === 'gif'){
            return $this;
        }

        $config = $this->getConfig();
        if($extension === 'png' && $config->png_optimize->enable){
            $featureClass = 'EvaThumber\Feature\Pngout';
            if(false === $featureClass::isSupport()){
                return $this;
            }

            $feature = new $featureClass($config->png_optimize->pngout->bin);
            $this->optimizedImage = $feature->filterDump($this->getImage());
            if($this->optimizedImage){
                $this->optimized = true;
            }
        }

        return $this;
    }

}
