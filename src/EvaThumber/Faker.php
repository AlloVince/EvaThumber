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
        if(false === in_array($sourceSite, array('flickr', 'picasa'))){
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
            $rss = 'http://www.flickr.com/explore?data=1';
            break;
            case 'picasa':
            default:
            $rss = 'https://picasaweb.google.com/data/feed/api/featured?alt=json';
        }
        return $rss;
    }

    protected function process()
    {
        $sourceSite = $this->getSourceSite();
        $json = $this->getRss();
        $request = Requests::get($json);
        $data = json_decode($request->body);

        switch($sourceSite){
            case 'flickr':
            $entry = $data->photos;
            $count = count($entry);
            $url = $entry[rand(0, $count - 1)]->sizes->c->url; //use medium size
            break;
            case 'picasa':
            default:
            $entry = $data->feed->entry;
            $count = count($entry);
            $url = $entry[rand(0, $count - 1)]->content->src;
        }
        return $url;

    }

    public function getFile()
    {
        return $this->process();
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
