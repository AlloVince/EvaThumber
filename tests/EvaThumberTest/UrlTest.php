<?php
namespace EvaThumberTest;

use EvaThumber;
use EvaThumber\Url;
use EvaThumber\Config\Config;

class UrlTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
    }


    public function testGetCurrentUrl()
    {
        $_SERVER = array(
            'SERVER_PORT' => '80',
            'SERVER_NAME' => 'localhost',
            'REQUEST_URI' => '/abc.php',
        );
        $url = new Url();
        $this->assertEquals('http://localhost/abc.php', $url->getCurrentUrl());


        $_SERVER = array(
            'HTTPS' => 'on',
            'SERVER_PORT' => '8080',
            'SERVER_NAME' => 'abc',
            'REQUEST_URI' => '/',
        );
        $url = new Url();
        $this->assertEquals('https://abc:8080/', $url->getCurrentUrl());
    }

    public function testBasicUrlParse()
    {
        $url = new Url('http://localhost/EvaThumber/?foo=bar&aaa=bbb');
        $this->assertEquals('http://localhost/EvaThumber/?foo=bar&aaa=bbb', $url->getUrlString());
        $this->assertEquals('http', $url->getScheme());
        $this->assertEquals('localhost', $url->getHost());
        $this->assertEquals('foo=bar&aaa=bbb', $url->getQuery());


        $url = new Url('');
        $this->assertEquals('', $url->getUrlString());
        $this->assertEquals('', $url->getScheme());
        $this->assertEquals('', $url->getHost());
        $this->assertEquals('', $url->getQuery());
    }

    public function testGetUrlScriptName()
    {
    
    }
}
