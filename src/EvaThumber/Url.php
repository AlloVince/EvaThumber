<?php


namespace EvaThumber;

class Url
{
    protected $urlString;

    protected $urlPath;

    protected $urlScriptName;

    protected $urlImagePath;

    protected $urlImageName;

    protected $urlRewriteEnabled;

    protected $urlRewritePath;

    public function toArray()
    {
        return array(
            'urlString' => $this->urlString,
            'urlPath' => $this->getUrlPath(),
            'urlScriptName' => $this->getUrlScriptName(),
            'urlImagePath' => $this->getUrlImagePath(),
            'urlImageName' => $this->getUrlImageName(),
            'urlRewriteEnabled' => $this->getUrlRewriteEnabled(),
            'urlRewritePath' => $this->getUrlRewritePath(),
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
        //return $this->urlImagePath =
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
