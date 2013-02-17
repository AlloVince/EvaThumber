<?php


namespace EvaThumber;

class Url
{
    protected $urlString;

    protected $urlPath;

    protected $urlPrefix;

    protected $urlScriptName;

    protected $urlImagePath;

    protected $urlImageName;

    protected $urlRewriteEnabled;

    protected $urlRewritePath;

    protected $imagePath;

    protected $imageName;

    public function toArray()
    {
        return array(
            'urlString' => $this->urlString,
            'urlPath' => $this->getUrlPath(),
            'urlPrefix' => $this->getUrlPrefix(),
            'urlKey' => $this->getUrlKey(),
            'urlScriptName' => $this->getUrlScriptName(),
            'urlImagePath' => $this->getUrlImagePath(),
            'urlImageName' => $this->getUrlImageName(),
            'urlRewriteEnabled' => $this->getUrlRewriteEnabled(),
            'urlRewritePath' => $this->getUrlRewritePath(),
            'imagePath' => $this->getImagePath(),
            'imageName' => $this->getImageName(),
        );
    }

    public function getUrlRewriteEnabled()
    {
        if($this->urlRewriteEnabled !== null){
            return $this->urlRewriteEnabled;
        }

        $urlScriptName = $this->getUrlScriptName();
        if(!$urlScriptName){
            return $this->urlRewriteEnabled = false;
        }

        $urlScriptArray = explode('/', $urlScriptName);
        $selfFileName = array_pop($urlScriptArray);
        if(false !== strpos($selfFileName, '.php')){
            return $this->urlRewriteEnabled = true;
        }
        return $this->urlRewriteEnabled = false;
    }

    public function getUrlPath()
    {
        if($this->urlPath){
            return $this->urlPath;
        }

        if(!$this->urlString){
            return '';
        }

        $url = $this->urlString;
        $url = parse_url($url);
        return $this->urlPath = $url['path'];
    }

    public function getUrlPrefix()
    {
        $urlImagePath = $this->getUrlImagePath();
        $urlImagePathArray = explode('/', ltrim($urlImagePath, '/'));
        if(count($urlImagePathArray) < 2){
            return '';
        }
        return $this->urlPrefix = array_shift($urlImagePathArray);
    }

    public function getUrlKey()
    {
        $urlImagePath = $this->getUrlImagePath();
        $urlImagePathArray = explode('/', ltrim($urlImagePath, '/'));
        if(count($urlImagePathArray) < 3){
            return '';
        }
        return $this->urlKey = $urlImagePathArray[1];
    }

    public function getUrlScriptName()
    {
        if($this->urlScriptName){
            return $this->urlScriptName;
        }

        if(isset($_SERVER['SCRIPT_NAME'])){
            return $this->urlScriptName = $_SERVER['SCRIPT_NAME'];
        }

        return '';
    }

    public function getUrlImagePath()
    {
        if($this->urlImagePath){
            return $this->urlImagePath;
        }

        $urlPath = $this->getUrlPath();
        if(!$urlPath){
            return '';
        }

        $urlScriptName = $this->getUrlScriptName();


        if($urlScriptName){
            $urlRewriteEnabled = $this->getUrlRewriteEnabled();
            if($urlRewriteEnabled) {
            
                return $this->urlImagePath = str_replace($this->getUrlRewritePath(), '', $urlPath);
            } else {
                return $this->urlImagePath = str_replace($urlScriptName, '', $urlPath);
            }
        } else {
            return $this->urlImagePath = $urlPath;
        }
    }

    public function getUrlImageName()
    {
        if($this->urlImageName){
            return $this->urlImageName;
        }

        $urlImagePath = $this->getUrlImagePath();
        $urlImagePathArray = explode('/', $urlImagePath);
        return $this->urlImageName = array_pop($urlImagePathArray);
    }

    public function setUrlImageName($imageName)
    {
        $this->urlImageName = $imageName;
        return $this;
    }

    public function getImagePath()
    {
        $urlImagePath = $this->getUrlImagePath();
        $urlImagePathArray = explode('/', ltrim($urlImagePath, '/'));
        if(count($urlImagePathArray) < 4){
            return '';
        }

        //remove url prefix
        array_shift($urlImagePathArray);
        //remove url key
        array_shift($urlImagePathArray);
        //remove imagename
        array_pop($urlImagePathArray);
        return $this->imagePath = '/'. implode('/', $urlImagePathArray);
    
    }

    public function getImageName()
    {
        $urlImageName = $this->getUrlImageName();
        if(!$urlImageName){
            return '';
        }

        $fileNameArray = explode('.', $urlImageName);
        if(!$fileNameArray || count($fileNameArray) < 2){
            throw new Exception\InvalidArgumentException('File name not correct');
        }
        $fileExt = array_pop($fileNameArray);
        $fileNameMain = implode('.', $fileNameArray);
        $fileNameArray = explode(',', $fileNameMain);
        if(!$fileExt || !$fileNameArray || !$fileNameArray[0]){
            throw new Exception\InvalidArgumentException('File name not correct');
        }
        $fileNameMain = array_shift($fileNameArray);

        return $this->imageName = $fileNameMain . '.' . $fileExt;
    }

    public function getUrlRewritePath()
    {
        $scriptName = $this->getUrlScriptName();
        if(false === $this->getUrlRewriteEnabled()){
            return $this->urlRewritePath = $scriptName;
        }

        $rewitePathArray = explode('/', $scriptName);
        array_pop($rewitePathArray);
        return $this->urlRewritePath = implode('/', $rewitePathArray);
    }

    public function toString()
    {
    
    }

    public function __construct($url = null)
    {
        $url = $url ? $url : $this->getCurrentUrl();
        $this->urlString = $url;
    }

    public function getCurrentUrl()
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
}
