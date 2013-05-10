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

/**
 * Parse Url as EvaThumber necessary parts
 * - Example : http://localhost/EvaThumber/thumb/zip/archive/zipimage,w_100.jpg?query=123
 * Will be parse to :
 * -- scheme : http
 * -- host : localhost
 * -- query : query=123
 * -- urlScriptName : /EvaThumber/index.php
 * -- urlRewritePath : /EvaThumber
 * -- urlPrefix : thumb
 * -- urlKey : zip
 * -- urlImagePath : /thumb/zip/archive/zipimage,w_100.jpg
 * -- urlImageName : zipimage,w_100.jpg
 * -- urlRewriteEnabled : true
 * -- imagePath : /archive
 * -- imageName : zipimage.jpg
 * 
 */
class Url
{
    /**
    * @var string
    */
    protected $scheme;

    /**
    * @var string
    */
    protected $host;

    /**
    * @var string
    */
    protected $port;

    /**
    * @var string
    */
    protected $query;

    /**
    * @var string Original URL
    */
    protected $urlString;

    /**
    * @var string
    */
    protected $urlPath;

    /**
    * @var string
    */
    protected $urlPrefix;

    /**
    * @var string
    */
    protected $urlScriptName;

    /**
    * @var string
    */
    protected $urlImagePath;

    /**
    * @var string
    */
    protected $urlImageName;

    /**
    * @var boolean
    */
    protected $urlRewriteEnabled;

    /**
    * @var string
    */
    protected $urlRewritePath;

    /**
    * @var string
    */
    protected $imagePath;

    /**
    * @var string
    */
    protected $imageName;

    public function toArray()
    {
        return array(
            'urlString' => $this->urlString,
            'urlPath' => $this->getUrlPath(),
            'scheme' => $this->getScheme(),
            'host' => $this->getHost(),
            'query' => $this->getQuery(),
            'urlScriptName' => $this->getUrlScriptName(), //from $_SERVER
            'urlRewritePath' => $this->getUrlRewritePath(),
            'urlPrefix' => $this->getUrlPrefix(),
            'urlKey' => $this->getUrlKey(),
            'urlImagePath' => $this->getUrlImagePath(),
            'urlImageName' => $this->getUrlImageName(),
            'urlRewriteEnabled' => $this->getUrlRewriteEnabled(),
            'imagePath' => $this->getImagePath(),
            'imageName' => $this->getImageName(),
        );
    }

    public function getScheme()
    {
        return $this->scheme;
    }

    public function getHost()
    {
        return $this->host;
    }

    public function getPort()
    {
        return $this->port;
    }

    public function getQuery()
    {
        return $this->query;
    }

    public function getUrlString()
    {
        return $this->urlString;
    }

    public function getUrlRewriteEnabled()
    {
        if($this->urlRewriteEnabled !== null){
            return $this->urlRewriteEnabled;
        }

        $urlPath = $this->getUrlPath();
        if(false === strpos($urlPath, '.php')){
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
        return $this->urlPath = isset($url['path']) ? $url['path'] : '';
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

    public function setUrlScriptName($urlScriptName)
    {
        $this->urlScriptName = (string) $urlScriptName;
        return $this;
    }

    public function getUrlScriptName()
    {
        if($this->urlScriptName){
            return $this->urlScriptName;
        }

        if(isset($_SERVER['SCRIPT_NAME']) && $_SERVER['SCRIPT_NAME']){
            $scriptName = $_SERVER['SCRIPT_NAME'];
            if(false === strpos($scriptName, '.php')){
                return $this->urlScriptName = '';
            }

            //Nginx maybe set SCRIPT_NAME as full url path
            if(($scriptNameEnd = substr($scriptName, -4)) && $scriptNameEnd === '.php'){
                return $this->urlScriptName = $scriptName;
            } else {
                $scriptNameArray = explode('/', $scriptName);
                $scriptName = array();
                foreach($scriptNameArray as $scriptNamePart){
                    $scriptName[] = $scriptNamePart;
                    if(false !== strpos($scriptNamePart, '.php')){
                        break;
                    }
                }
                return $this->urlScriptName = implode('/', $scriptName);
            }
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
        if(!$urlImagePath){
            return $this->urlImageName = '';
        }

        $urlImagePathArray = explode('/', $urlImagePath);
        $urlImageName = array_pop($urlImagePathArray);

        //urlImageName must have extension part
        $urlImageNameArray = explode('.', $urlImageName);
        $urlImageNameCount = count($urlImageNameArray);
        if($urlImageNameCount < 2 || !$urlImageNameArray[$urlImageNameCount - 1]){
            return $this->urlImageName = '';
        }
        return $this->urlImageName = $urlImageName;
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
            return $this->imageName = '';
        }

        $fileNameArray = explode('.', $urlImageName);
        if(!$fileNameArray || count($fileNameArray) < 2){
            return $this->imageName = '';
        }
        $fileExt = array_pop($fileNameArray);
        $fileNameMain = implode('.', $fileNameArray);
        $fileNameArray = explode(',', $fileNameMain);
        if(!$fileExt || !$fileNameArray || !$fileNameArray[0]){
            return $this->imageName = '';
        }
        $fileNameMain = array_shift($fileNameArray);

        return $this->imageName = $fileNameMain . '.' . $fileExt;
    }

    public function getUrlRewritePath()
    {
        $scriptName = $this->getUrlScriptName();
        if(!$scriptName){
            return $this->urlRewritePath = '';
        }

        if(false === $this->getUrlRewriteEnabled()){
            return $this->urlRewritePath = $scriptName;
        }

        $rewitePathArray = explode('/', $scriptName);
        array_pop($rewitePathArray);
        return $this->urlRewritePath = implode('/', $rewitePathArray);
    }


    public function isValid()
    {
        $host = $this->getHost();
        if(!$host){
            return false;
        }

        if(!$this->getUrlPrefix()){
            return false;
        }

        if(!$this->getUrlKey()){
            return false;
        }

        if(!$this->getImageName()){
            return false;
        }

        return true;
    }

    public function toString()
    {
        $host = $this->getHost();
        if(!$host){
            return '';
        }

        $port = $this->getPort();
        if($port != ''){
            $host .= ':'.$port;
        }

        $path = $this->getUrlRewritePath();
        if($prefix = $this->getUrlPrefix()){
            $path .= "/$prefix"; 
        }

        if($urlKey = $this->getUrlKey()){
            $path .= "/$urlKey";
        }

        if($imagePath = $this->getImagePath()){
            $path .= $imagePath;
        }

        if($imageName = $this->getUrlImageName()){
            $path .= '/' . $imageName;
        }

        $url = $this->getScheme() . '://' . $host . $path;
        $url .= $this->getQuery() ? '?' . $this->getQuery() : '';
        return $url;
    }



    public function getCurrentUrl()
    {
        $serverName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
        $requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

        if(!$serverName){
            return '';
        }

        $pageURL = 'http';
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on'){
            $pageURL .= 's';
        }
        $pageURL .= '://';

        if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] != '80'){
            $pageURL .= $serverName . ':' . $_SERVER['SERVER_PORT'] . $requestUri;
        } else {
            $pageURL .= $serverName . $requestUri;
        }
        return $pageURL;
    }

    public function __construct($url = null)
    {
        $urlString = $url ? $url : $this->getCurrentUrl();
        $this->urlString = $urlString;
        if($urlString){
            $url = parse_url($urlString);
            $this->scheme = isset($url['scheme']) ? $url['scheme'] : null;
            $this->host = isset($url['host']) ? $url['host'] : null;
            $this->port = isset($url['port']) ? $url['port'] : '';
            $this->query = isset($url['query']) ? $url['query'] : null;
            $this->urlPath = isset($url['path']) ? $url['path'] : null;
        }
    }
}
