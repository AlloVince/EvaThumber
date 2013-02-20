<?php
namespace EvaThumber;

use Requests;

class Faker
{
    //protected $httpRequest;

    protected $file;

    protected $sourceSite;

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

    /*
    public function getHttpRequest()
    {
        if($this->httpRequest){
            return $this->httpRequest;
        }

        return $this->httpRequest = new Requests();
    }

    public function setHttpRequest(Requests $httpRequest)
    {
        $this->httpRequest = $httpRequest;
        return $this;
    }
    */

    public function getSourceSite()
    {
        return $this->sourceSite;
    }

    public function setSourceSite($sourceSite)
    {
        $sourceSite = strtolower($sourceSite);
        if(false === in_array($dummy, array('flickr', 'picasa'))){
            $sourceSite = 'picasa';
        }
        $this->sourceSite = $sourceSite;
        return $this;
    }

    public function getRss()
    {
        $sourceSite = $this->getSourceSite();
        switch($sourceSite){
            case 'flickr':
            break;
            case 'picasa':
            default:
            $rss = 'https://picasaweb.google.com/data/feed/api/featured?alt=json';
        }
        return $rss;
    }

    protected function process()
    {
    
    }

    public function getFile()
    {
        $json = $this->getRss();
        $request = Requests::get($json);
        $data = json_decode($request->body);
        $entry = $data->feed->entry;
        $count = count($entry);
        $url = $entry[rand(0, $count - 1)]->content->src;
        return $url;
    }

    public function __construct($sourceSite = null)
    {
        if(false === class_exists('Requests')){
            throw new Exception\BadFunctionCallException(sprintf(
                'Library Requests not installed'
            ));
        }

        if($sourceSite){
            $this->setSourceSite($sourceSite);
        }
    }
}
