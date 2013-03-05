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
    }
}
