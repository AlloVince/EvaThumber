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
        $_SERVER = array(
            'SCRIPT_NAME' => '/EvaThumber/index.php',
        );
        $url = new Url();
        $this->assertEquals('/EvaThumber/index.php', $url->getUrlScriptName());

        $_SERVER = array(
            'SCRIPT_NAME' => '/EvaThumber/index.php/thumb/d/demo.jpg',
        );
        $url = new Url();
        $this->assertEquals('/EvaThumber/index.php', $url->getUrlScriptName());


        $_SERVER = array(
            'SCRIPT_NAME' => '/index.php',
        );
        $url = new Url();
        $this->assertEquals('/index.php', $url->getUrlScriptName());

        $_SERVER = array(
            'SCRIPT_NAME' => '/thumb/d/demo.jpg',
        );
        $url = new Url();
        $this->assertEquals('', $url->getUrlScriptName());

        $_SERVER = array(
            'SCRIPT_NAME' => '/',
        );
        $url = new Url();
        $this->assertEquals('', $url->getUrlScriptName());
        $url->setUrlScriptName('foo');
        $this->assertEquals('foo', $url->getUrlScriptName());

    }


    public function testUrlRewriting()
    {
        $url = new Url('http://localhost/EvaThumber/index.php/thumb/d/demo.jpg');
        $this->assertEquals(false, $url->getUrlRewriteEnabled());

        $url = new Url('http://localhost/EvaThumber/thumb/d/demo.jpg');
        $this->assertEquals(true, $url->getUrlRewriteEnabled());

        $url = new Url('http://localhost.php/EvaThumber/thumb/d/demo.jpg');
        $this->assertEquals(true, $url->getUrlRewriteEnabled());
    }


    public function testGetUrlRewritePath()
    {
        $_SERVER = array(
            'SCRIPT_NAME' => '/EvaThumber/index.php',
        );
        $url = new Url('http://localhost/EvaThumber/index.php/thumb/d/demo.jpg');
        $this->assertEquals('/EvaThumber/index.php', $url->getUrlRewritePath());

        $url = new Url('http://localhost/EvaThumber/thumb/d/demo.jpg');
        $this->assertEquals('/EvaThumber', $url->getUrlRewritePath());


        $_SERVER = array(
            'SCRIPT_NAME' => 'index.php',
        );
        $url = new Url('http://localhost/thumb/d/demo.jpg');
        $this->assertEquals('', $url->getUrlRewritePath());

        $_SERVER = array(
            'SCRIPT_NAME' => '',
        );
        $url = new Url('http://localhost/thumb/d/demo.jpg');
        $this->assertEquals('', $url->getUrlRewritePath());
    }

    public function testGetUrlImagePath()
    {
        $_SERVER = array(
            'SCRIPT_NAME' => '/EvaThumber/index.php',
        );
        $url = new Url('http://localhost/EvaThumber/index.php/thumb/d/demo.jpg');
        $this->assertEquals('/thumb/d/demo.jpg', $url->getUrlImagePath());


        $_SERVER = array(
            'SCRIPT_NAME' => '/index.php',
        );
        $url = new Url('http://localhost/EvaThumber/index.php/thumb/d/demo.jpg');
        $this->assertEquals('/EvaThumber/thumb/d/demo.jpg', $url->getUrlImagePath());
    }

    public function testGetUrlPrefix()
    {
        $url = new Url('http://localhost/EvaThumber/index.php/thumb/d/demo.jpg');
        $url->setUrlScriptName('/EvaThumber/index.php');
        $this->assertEquals('thumb', $url->getUrlPrefix());


        $url = new Url('http://localhost/EvaThumber/thumb/d/demo.jpg');
        $url->setUrlScriptName('/EvaThumber/index.php');
        $this->assertEquals('thumb', $url->getUrlPrefix());
    
        $url = new Url('http://localhost/');
        $this->assertEquals('', $url->getUrlPrefix());

        $url = new Url('http://localhost/thumb');
        $this->assertEquals('', $url->getUrlPrefix());

        $url = new Url('http://localhost/thumb/');
        $this->assertEquals('thumb', $url->getUrlPrefix());

        $url = new Url('http://localhost/index.php/abc.jpg');
        $this->assertEquals('index.php', $url->getUrlPrefix());
    }

    public function testGetUrlKey()
    {
        $url = new Url('http://localhost/EvaThumber/index.php/thumb/d/demo.jpg');
        $url->setUrlScriptName('/EvaThumber/index.php');
        $this->assertEquals('d', $url->getUrlKey());


        $url = new Url('http://localhost/EvaThumber/thumb/d/demo.jpg');
        $url->setUrlScriptName('/EvaThumber/index.php');
        $this->assertEquals('d', $url->getUrlKey());
    
        $url = new Url('http://localhost/');
        $this->assertEquals('', $url->getUrlKey());

        $url = new Url('http://localhost/thumb');
        $this->assertEquals('', $url->getUrlKey());

        $url = new Url('http://localhost/thumb/');
        $this->assertEquals('', $url->getUrlKey());

        $url = new Url('http://localhost/thumb/d');
        $this->assertEquals('', $url->getUrlKey());

        $url = new Url('http://localhost/thumb/d/');
        $this->assertEquals('d', $url->getUrlKey());

        $url = new Url('http://localhost/thumb/d/foo/bar/demo.jpg');
        $this->assertEquals('d', $url->getUrlKey());
    }


    public function testGetImagePath()
    {
        $url = new Url('http://localhost/EvaThumber/thumb/d/demo.jpg');
        $url->setUrlScriptName('/EvaThumber/index.php');
        $this->assertEquals('', $url->getImagePath());
    
        $url = new Url('http://localhost/EvaThumber/thumb/d/foo/demo.jpg');
        $url->setUrlScriptName('/EvaThumber/index.php');
        $this->assertEquals('/foo', $url->getImagePath());

        $url = new Url('http://localhost/EvaThumber/thumb/d/foo/bar/demo.jpg');
        $url->setUrlScriptName('/EvaThumber/index.php');
        $this->assertEquals('/foo/bar', $url->getImagePath());

        $url = new Url('http://localhost/thumb/d/foo');
        $this->assertEquals('', $url->getImagePath());

        $url = new Url('http://localhost/thumb/d/foo/');
        $this->assertEquals('/foo', $url->getImagePath());
    }

    public function testGetUrlImageName()
    {
        $url = new Url('http://localhost/EvaThumber/thumb/d/demo,w_100.jpg');
        $url->setUrlScriptName('/EvaThumber/index.php');
        $this->assertEquals('demo,w_100.jpg', $url->getUrlImageName());

        $url = new Url('http://localhost/');
        $this->assertEquals('', $url->getUrlImageName());

        $url = new Url('http://localhost/thumb/d/foo/bar');
        $this->assertEquals('', $url->getUrlImageName());
    
        $url = new Url('http://localhost/thumb/d/foo/bar.');
        $this->assertEquals('', $url->getUrlImageName());
    }

    public function testGetImageName()
    {
        $url = new Url('http://localhost/EvaThumber/thumb/d/demo,w_100.jpg');
        $url->setUrlScriptName('/EvaThumber/index.php');
        $this->assertEquals('demo.jpg', $url->getImageName());

        $url = new Url('http://localhost/');
        $this->assertEquals('', $url->getImageName());

        $url = new Url('http://localhost/thumb/d/demo.jpg');
        $this->assertEquals('demo.jpg', $url->getImageName());
    
        $url = new Url('http://localhost/thumb/d/demo,w_100,,,.jpg');
        $this->assertEquals('demo.jpg', $url->getImageName());
    
        $url = new Url('http://localhost/thumb/d/demo...,w_100,,,.jpg');
        $this->assertEquals('demo....jpg', $url->getImageName());
    
        $url = new Url('http://localhost/thumb/d/demo,w_100....jpg');
        $this->assertEquals('demo.jpg', $url->getImageName());
    
    }


    public function testToString()
    {
        $url = new Url('');
        $this->assertEquals('', $url->toString());

        $url = new Url('foo');
        $this->assertEquals('', $url->toString());

        $url = new Url('http://localhost');
        $this->assertEquals('http://localhost', $url->toString());

        $url = new Url('http://localhost/thumb/d/foo/bar/demo,w_100.jpg');
        $url->setUrlImageName('demo,w_200.jpg');
        $this->assertEquals('http://localhost/thumb/d/foo/bar/demo,w_200.jpg', $url->toString());
    }

    public function testIsValid()
    {
    
        $url = new Url('');
        $this->assertEquals(false, $url->isValid());

        $url = new Url('foo');
        $this->assertEquals(false, $url->isValid());

        $url = new Url('http://localhost');
        $this->assertEquals(false, $url->isValid());

        $url = new Url('http://localhost/thumb/test.jpg');
        $this->assertEquals(false, $url->isValid());

        $url = new Url('http://localhost/thumb/d/test');
        $this->assertEquals(false, $url->isValid());

        $url = new Url('http://localhost/thumb/d/test.jpg');
        $this->assertEquals(true, $url->isValid());

    }
}
