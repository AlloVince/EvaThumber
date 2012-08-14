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

/** Debug functions */
function p($r, $usePr = false)
{
    echo '<pre>' . var_dump($r, true) . '</pre>';
}

class EvaCloudImage
{
    
    protected $relativePath;
    protected $subPath;
    protected $pathlevel;
    protected $sourceImageName;
    protected $targetImageName;
    protected $uniqueTargetImageName;
    protected $imageNameArgs = array();

    protected $sourceImage;
    protected $targetImage;
    protected $url;


    protected $options = array(
        'engine' => 'GD', //or imageMagick
        'libPath' => '',
        'sourceRootPath' => '',
        'thumbFileRootPath' => '',
        'thumbUrlRootPath' => '',
        'maxAllowWidth' => '',
        'maxAllowHeight' => '',
        'watermark' => array(
            'enable' => false,
            'enableWidth' => 500,
            'enableHeight' => 400,
            'position' => '',
            'text' => 'watermark',
            'font' => '',
            'fontfile' => '',
        ),
        'saveImage' => true,
        'allowExpendResize' => false,
        'fileSizeLimit' => 1048576,  //1MB = 1 048 576 bytes
    );

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
    protected $transferParameters = array(
        'width' => null,
        'height' => null,
        'quality' => null, 
        'crop' => null,
        'x' => null,
        'y' => null,
        'rotate' => null,
        'gravity' => null,
    );
    protected $transferParametersMerged = false;

    public static function url($url, array $nameArgs = array())
    {
        $evaCloudImage = new static($url);

        //source image will reset imageNameArgs once
        $evaCloudImage->getSourceImageName();
        return $evaCloudImage->setImageNameArgs($nameArgs)->getUniqueUrl();
    }


    public function setImageNameArgs(array $imageNameArgs)
    {
        $this->imageNameArgs = $imageNameArgs;
        return $this;
    }

    public function getImageNameArgs()
    {
        return $this->imageNameArgs;
    }

    public function setTransferParametersMerged($merged)
    {
        $this->transferParametersMerged = (boolean) $merged;
        return $this;
    }

    public function setUrl($url)
    {
        $this->url = $url;
        return $this;
    }

    public function getUrl()
    {
        return $this->url;
    }

    public function getUniqueUrl()
    {
        $url = $this->url;
        $urlArray = explode('/', $url);
        array_pop($urlArray);
        array_push($urlArray, $this->getUniqueTargetImageName());

        return implode('/', $urlArray);
    }


    public function getTransferParameters()
    {
        if(true === $this->transferParametersMerged){
            return $this->transferParameters;
        }

        $params = $this->argsToParameters();
        $this->transferParametersMerged = true;
        return $this->transferParameters = array_merge($this->transferParameters, $params);
    }

    public function setTransferParameters(array $transferParameters)
    {
        $this->transferParameters = $transferParameters;
        return $this;
    }


    protected function getCurrentUrl()
    {
        $pageURL = 'http';

        if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on"){
            $pageURL .= "s";
        }
        $pageURL .= "://";

        if ($_SERVER["SERVER_PORT"] != "80"){
            $pageURL .= $_SERVER["SERVER_NAME"] . ":" . $_SERVER["SERVER_PORT"] . $_SERVER["REQUEST_URI"];
        }
        else {
            $pageURL .= $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
        }
        return $pageURL;
    }

    protected function getRelativePath()
    {
        if($this->relativePath){
            return $this->relativePath;
        }

        $options = $this->options;

        if(!$options['thumbFileRootPath']){
            throw new InvalidArgumentException('Thumb file path not set');
        }
        if(!$options['thumbUrlRootPath']){
            throw new InvalidArgumentException('Thumb file url path not set');
        }

        //NOTE : realpath performance not good
        $relativePath = str_replace(realpath($options['thumbUrlRootPath']), '', realpath($options['thumbFileRootPath']));
        if($relativePath) {
            $relativePath = trim($relativePath, '/\\');
        }

        return $this->relativePath = $relativePath;
    }

    protected function getSubPath($urlPath = null)
    {
        if(!empty($this->subPath)){
            return $this->subPath;
        }

        if(!$urlPath){
            $url = $this->url;
            $url = parse_url($url);
            $urlPath = $url['path'];
        }

        if(!$urlPath){
            return $this->subPath = '';
        }

        $relativePath = '/' . str_replace('\\', '/', $this->getRelativePath());
        $filePath = str_replace($relativePath, '', $urlPath);
        $filePath = trim(str_replace('/', DIRECTORY_SEPARATOR, $filePath), DIRECTORY_SEPARATOR);

        $pathArray = explode(DIRECTORY_SEPARATOR, $filePath);
        //remove file extension
        array_pop($pathArray);
        $this->pathlevel = count($pathArray);
        $filePath = implode(DIRECTORY_SEPARATOR, $pathArray);
        return $this->subPath = $filePath;
    }

    public function getTargetImage()
    {
        if($this->targetImage){
            return $this->targetImage;
        }

        $options = $this->options;
        $subPath = $this->getSubPath();
        //$fileName = $this->getTargetImageName();
        $uniqueName = $this->getUniqueTargetImageName();

        return $this->targetImage = $options['thumbFileRootPath'] . DIRECTORY_SEPARATOR . $subPath . DIRECTORY_SEPARATOR . $uniqueName;
    }

    /*
    public function getTargetImageName($urlPath = null)
    {
        if($this->targetImageName){
            return $this->targetImageName;
        }
        $url = $this->url;
        $url = parse_url($url);
        $urlPath = $url['path'];

        $urlArray = explode('/', $urlPath);
        $fileName = $urlArray[count($urlArray) - 1];
        $fileNameArray = $fileName ? explode('.', $fileName) : array();
        if(!$fileNameArray || !isset($fileNameArray[1]) || !$fileNameArray[0] || !$fileNameArray[1]){
            throw new InvalidArgumentException('File name not correct');
        }

        return $this->targetImageName = $fileName;
    }
    */

    public function getUniqueTargetImageName()
    {
        if($this->uniqueTargetImageName){
            return $this->uniqueTargetImageName;
        }

        $sourceImageName = $this->getSourceImageName();

        $this->getUniqueParameters();
        $argString = $this->parametersToString();
        if(!$argString){
            return $this->uniqueTargetImageName = $sourceImageName;
        }
        $nameArray = explode('.', $sourceImageName);
        $nameExt = array_pop($nameArray);
        $nameFinal = array_pop($nameArray);
        $nameFinal .= ',' . $argString;
        array_push($nameArray, $nameFinal, $nameExt);
        $uniqueName = implode('.', $nameArray);
        return $this->uniqueTargetImageName = $uniqueName;
    }



    public function getSourceImage()
    {
        if($this->sourceImage){
            return $this->sourceImage;
        }

        $url = $this->url;
        $options = $this->options;

        if(!$options['sourceRootPath']) {
            throw new InvalidArgumentException('Source file path not set');
        }

        $url = parse_url($url);
        if(!$url || !$url['path']){
            throw new InvalidArgumentException('Url not able to parse');
        }

        $sourceImageName = $this->getSourceImageName($url['path']);
        $subPath = $this->getSubPath($url['path']);
        return $this->sourceImage = $options['sourceRootPath'] . DIRECTORY_SEPARATOR . $subPath . DIRECTORY_SEPARATOR . $sourceImageName; 
    }

    public function getSourceImageName($urlPath = null)
    {
        if($this->sourceImageName){
            return $this->sourceImageName;
        }

        if(!$urlPath){
            $url = $this->url;
            $url = parse_url($url);
            $urlPath = $url['path'];
        }

        $urlArray = explode('/', $urlPath);
        $fileName = $urlArray[count($urlArray) - 1];
        $fileNameArray = $fileName ? explode('.', $fileName) : array();
        if(!$fileNameArray || count($fileNameArray) < 2){
            throw new InvalidArgumentException('File name not correct');
        }

        $this->targetImageName = $fileName;

        $fileExt = array_pop($fileNameArray);

        //TODO : add ext check

        $fileNameMain = implode('.', $fileNameArray);
        $fileNameArray = explode(',', $fileNameMain);
        if(!$fileExt || !$fileNameArray || !$fileNameArray[0]){
            throw new InvalidArgumentException('File name not correct');
        }

        //remove empty elements
        $fileNameArray = array_filter($fileNameArray);

        $fileNameMain = array_shift($fileNameArray);
        $this->imageNameArgs = $fileNameArray;
        return $this->sourceImageName = $fileNameMain . '.' . $fileExt;
    }

    public function setSourceImageName($sourceImageName)
    {
        $this->sourceImageName = $sourceImageName;
        return $this;
    }

    public function show()
    {
        $sourceImage = $this->getSourceImage();
        $targetImage = $this->getTargetImage();

        if(false === file_exists($sourceImage)){
            header('HTTP/1.1 404 Not Found');
            throw new Exception(printf('Source image is not exist, image path %s', $sourceImage));
        }

        $url = $this->getUniqueUrl();
        if($this->url != $url){
            header("HTTP/1.1 301 Moved Permanently");
            return header('Location:' . $url);
        }


        $options = $this->options;
        if(!$options['libPath']){
            throw new InvalidArgumentException('PHPThumb library path not set');
        }
        require_once $options['libPath'] . DIRECTORY_SEPARATOR . 'ThumbLib.inc.php';

        $thumb = PhpThumbFactory::create($sourceImage);
        $this->transferImage($thumb);

        if(true === $this->options['saveImage']){
            $this->prepareDirectoryStructure($targetImage, $this->pathlevel);
            $thumb->save($targetImage)->show(); 
        } else {
            $thumb->show(); 
        }
    }

    public function getUniqueParameters()
    {
        $params = $this->getTransferParameters();

        $requireResize = true;

        //TODO: how to know image real width & height in advance
        if($params['width'] || $params['height']) {
            $params['width'] = $params['width'] ? $params['width'] + 0 : null;
            $params['height'] = $params['height'] ? $params['height'] + 0 : null;

            if(is_int($params['width']) && is_int($params['height'])){
            } elseif(is_int($params['width']) || is_int($params['height'])){
                $params['width'] = !$params['width'] || is_float($params['width']) ? 0 : $params['width'];
                $params['height'] = !$params['height'] || is_float($params['height']) ? 0 : $params['height'];
            } else {
                $percent = $params['width'];
                $percent = !$percent || $percent > 0 && $percent < $params['height'] ? $params['height'] : $percent;
                $params['width'] = $percent;
                $params['height'] = null;
            }
        }

        if($params['x'] || $params['y']) {
            $params['x'] = $params['x'] ? $params['x'] + 0 : null;
            $params['y'] = $params['y'] ? $params['y'] + 0 : null;
        }

        if($params['crop']){
            $allowCrop = array('fill');
            if(is_numeric($params['crop'])){
                $params['crop'] = $params['crop'] + 0;
            } else {
                $params['crop'] = in_array($params['crop'], $allowCrop) ? $params['crop'] : null;
            }
        }

        if(!$params['crop']){
            $params['x'] = null;
            $params['y'] = null;
        }

        //Fill mode must have a certain width & height
        if($params['crop'] == 'fill' && (!is_int($params['width']) || !$params['width'] || !is_int($params['height']) || !$params['height'])){
            $params['crop'] = null;
        }

        if($params['gravity']){
            if(!$params['crop']){
                $params['gravity'] = null;
            }

            $allowGravity = array('top', 'bottom', 'right', 'left');
            if(is_numeric($params['gravity'])){
                $params['gravity'] = $params['gravity'] + 0;
            } else {
                $params['gravity'] = in_array($params['gravity'], $allowGravity) ? $params['gravity'] : null;
            }
        }

        if($params['gravity'] && $params['crop'] != 'fill' && in_array($params['gravity'], array('top', 'bottom', 'right', 'left'))){
            $params['gravity'] = null;
        }

        /*
        if($params['gravity'] == 'face'){
            $params['x'] = null;
            $params['y'] = null;
        }
        */

        if($params['rotate']){
            $allowRotate = array('CW', 'CCW');
            if(is_numeric($params['rotate'])){
                $params['rotate'] = $params['rotate'] + 0;
            } elseif(!in_array($params['rotate'], $allowRotate)) {
                $params['rotate'] = null;
            }
        }

        if($params['quality']){
            if(is_numeric($params['quality'])){
                $params['quality'] = $params['quality'] + 0;
            } else {
                $params['quality'] = null;
            }
        }

        return $this->transferParameters = $params;
    }

    public function transferImage(GdThumb $thumb)
    {
        $params = $this->getTransferParameters();

        if($params['crop'] && is_int($params['crop'])){
            if($params['x'] || $params['y']){
                $params['x'] = $params['x'] ? $params['x'] : 0;
                $params['y'] = $params['y'] ? $params['y'] : 0;

                if(is_int($params['gravity'])){
                    $thumb->crop($params['x'], $params['y'], $params['crop'], $params['gravity']);
                } else {
                    $thumb->crop($params['x'], $params['y'], $params['crop'], $params['crop']);
                }
            } else {
                if(is_int($params['gravity'])){

                    $thumb->cropFromCenter($params['crop'], $params['gravity']);
                } else {
                    if($params['gravity'] == 'face') {
                        $this->faceDetect($thumb);
                    }
                    $thumb->cropFromCenter($params['crop']);
                }
            }
        } else if($params['crop'] && is_string($params['crop'])){

            if($params['crop'] == 'fill'){
                $gravityMap = array(
                    'top' => 'T',
                    'left' => 'L',
                    'right' => 'R',
                    'bottom' => 'B',
                );
                if($params['gravity']) {
                    $thumb->adaptiveResizeQuadrant ($params['width'], $params['height'], $gravityMap[$params['gravity']]);
                } else {
                    $thumb->adaptiveResize($params['width'], $params['height']);
                }
            }
        }

        if( (!$params['crop'] || is_int($params['crop']) ) && ($params['width'] || $params['height'])) {
            if(is_int($params['width']) || is_int($params['height'])){
                $params['width'] = $params['width'] ? $params['width'] : 0;
                $params['height'] = $params['height'] ? $params['height'] : 0;
                $thumb->resize($params['width'], $params['height']);
            } else {
                $percent = $params['width'];
                $percent = $percent * 100;
                $thumb->resizePercent($percent);
            }
        }

        if($params['rotate']){
            if(is_int($params['rotate'])){
                $thumb->rotateImageNDegrees($params['rotate']);
            } else {
                $thumb->rotateImage($params['rotate']);
            }
        }

        if($params['quality']){
            $thumb->setOptions(array(
                'jpegQuality' => $params['quality']
            ));
        }
        return $thumb;
    }

    public function getFaceDetectClass()
    {
    }

    protected function faceDetect(GdThumb $thumb)
    {
    }

    public function __construct($url = null, array $options = array())
    {
        $url = $url ? $url : $this->getCurrentUrl();
        $this->url = $url;
        $options = array_merge($this->options, $options);
        $this->options = $options;
    }


    protected function argsToParameters()
    {
        $args = $this->imageNameArgs;
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

        return $params;
    }

    protected function parametersToString(array $params = array())
    {
        $params = $params ? $params : $this->getTransferParameters();
        $argMapping = array_flip($this->argMapping);
        $args = array();
        foreach($params as $key => $param){
            if(!$param){
                continue;
            }
            if(isset($argMapping[$key])){
                $args[$key] = $argMapping[$key] . '_' . $param;
            }
        }
        ksort($args);
        return implode(',', $args);
    }

    /**
    * Prepares a directory structure for the given file(spec)
    * using the configured directory level.
    * this method is from https://github.com/zendframework/zf2/blob/master/library/Zend/Cache/Storage/Adapter/Filesystem.php 
    *
    * @param string $file
    * @return void
    */
    protected function prepareDirectoryStructure($file, $level = '')
    {
        if (!$level) {
            return;
        }

        // Directory structure already exists
        $pathname = dirname($file);
        if (file_exists($pathname)) {
            return;
        }

        $perm     = 0700;
        $umask    = false;

        if ($umask !== false && $perm !== false) {
            $perm = $perm & ~$umask;
        }

        if ($perm === false || $level == 1) {
            // build-in mkdir function is enough

            $umask = ($umask !== false) ? umask($umask) : false;
            $res   = mkdir($pathname, ($perm !== false) ? $perm : 0777, true);

            if ($umask !== false) {
                umask($umask);
            }

            if (!$res) {
                $oct = ($perm === false) ? '777' : decoct($perm);
                throw new Exception(
                    "mkdir('{$pathname}', 0{$oct}, true) failed", 0, $err
                );
            }

            if ($perm !== false && !chmod($pathname, $perm)) {
                $oct = decoct($perm);
                throw new Exception(
                    "chmod('{$pathname}', 0{$oct}) failed", 0, $err
                );
            }

        } else {
            // build-in mkdir function sets permission together with current umask
            // which doesn't work well on multo threaded webservers
            // -> create directories one by one and set permissions

            // find existing path and missing path parts
            $parts = array();
            $path  = $pathname;
            while (!file_exists($path)) {
                array_unshift($parts, basename($path));
                $nextPath = dirname($path);
                if ($nextPath === $path) {
                    break;
                }
                $path = $nextPath;
            }

            // make all missing path parts
            foreach ($parts as $part) {
                $path.= DIRECTORY_SEPARATOR . $part;

                // create a single directory, set and reset umask immediatly
                $umask = ($umask !== false) ? umask($umask) : false;
                $res   = mkdir($path, ($perm === false) ? 0777 : $perm, false);
                if ($umask !== false) {
                    umask($umask);
                }

                if (!$res) {
                    $oct = ($perm === false) ? '777' : decoct($perm);
                    throw new Exception(
                        "mkdir('{$path}', 0{$oct}, false) failed"
                    );
                }

                if ($perm !== false && !chmod($path, $perm)) {
                    $oct = decoct($perm);
                    throw new Exception(
                        "chmod('{$path}', 0{$oct}) failed"
                    );
                }
            }
        }
    }
}
