<?php
namespace EvaThumberTest;

use EvaThumber;
use EvaThumber\Parameters;
use EvaThumber\Config\Config;

class ParametersTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {

    }


    public function testWidth()
    {
        $params = new Parameters();

        $params->setWidth(100);
        $this->assertEquals(100, $params->getWidth());

        $params->setWidth('200');
        $this->assertEquals(200, $params->getWidth());
    }

    public function testHeight()
    {
        $params = new Parameters();

        $params->setHeight(100);
        $this->assertEquals(100, $params->getHeight());

        $params->setHeight('200');
        $this->assertEquals(200, $params->getHeight());
    }

    public function testRotate()
    {
        $params = new Parameters();

        $params->setRotate(100);
        $this->assertEquals(100, $params->getRotate());

        $params->setRotate('200');
        $this->assertEquals(200, $params->getRotate());

        $params->setRotate('361');
        $this->assertEquals(1, $params->getRotate());
    }

    public function testStringParse()
    {
        $params = new Parameters();
        $config = new Config(array(
            'max_width' => 2000,
            'max_height' => 2000,
            'allow_stretch' => false,
        ));
        $params->setConfig($config);
        $params->fromString('filename,c_fill,w_100,h_200,r_90,q_80,d_picasa,f_gray.jpg');

        $this->assertEquals('filename', $params->getFilename());
        $this->assertEquals('fill', $params->getCrop());
        $this->assertEquals(100, $params->getWidth());
        $this->assertEquals(200, $params->getHeight());
        $this->assertEquals(90, $params->getRotate());
        $this->assertEquals(80, $params->getQuality());
        $this->assertEquals('picasa', $params->getDummy());
        $this->assertEquals('gray', $params->getFilter());
        $this->assertEquals('jpg', $params->getExtension());

        $params = new Parameters();
        $params->setConfig($config);
        $params->fromString('filename,c_100,g_50,x_0,y_1,p_70.jpg');
        $this->assertEquals('filename', $params->getFilename());
        $this->assertEquals(100, $params->getCrop());
        $this->assertEquals(50, $params->getGravity());
        $this->assertEquals(0, $params->getX());
        $this->assertEquals(1, $params->getY());
        $this->assertEquals(70, $params->getPercent());
        $this->assertEquals('jpg', $params->getExtension());
    }

    /**
    * @expectedException EvaThumber\Exception\InvalidArgumentException
    */
    public function testBadStringParseShouldThrowInvalidArgumentException()
    {
        $params = new Parameters();
        $params->fromString('');
    }

    /**
    * @expectedException EvaThumber\Exception\InvalidArgumentException
    */
    public function testBadStringParse2ShouldThrowInvalidArgumentException()
    {
        $params = new Parameters();
        $params->fromString('abc.');
    }

    /**
    * @expectedException EvaThumber\Exception\InvalidArgumentException
    */
    public function testBadStringParse3ShouldThrowInvalidArgumentException()
    {
        $params = new Parameters();
        $params->fromString('.abc');
    }

    public function testWidthHeightMaxLimit()
    {
        $params = new Parameters();
        $config = new Config(array(
            'max_width' => 200,
            'max_height' => 100,
        ));
        $params->setConfig($config);
        $params->fromString('filename,w_300,h_300.jpg');
        $this->assertEquals(200, $params->getWidth());
        $this->assertEquals(100, $params->getHeight());


        $params = new Parameters();
        $params->setConfig($config);
        $params->setImageSize(300, 400);
        $params->fromString('filename,w_300,h_300.jpg');
        $this->assertEquals(200, $params->getWidth());
        $this->assertEquals(100, $params->getHeight());


        $params = new Parameters();
        $params->setConfig($config);
        $params->setImageSize(100, 50);
        $params->fromString('filename,w_300,h_300.jpg');
        $this->assertEquals(100, $params->getWidth());
        $this->assertEquals(50, $params->getHeight());

        $params = new Parameters();
        $config = new Config(array(
            'max_width' => 200,
            'max_height' => 100,
            'allow_stretch' => true,
        ));
        $params->setConfig($config);
        $params->setImageSize(100, 50);
        $params->fromString('filename,w_300,h_300.jpg');
        $this->assertEquals(200, $params->getWidth());
        $this->assertEquals(100, $params->getHeight());
    }


    public function testSkipEmptyParameters()
    {
        $params = new Parameters();
        $params->fromString('abc,,,,.png');
        $this->assertEquals('abc.png', $params->toString());
        
        $params = new Parameters();
        $params->fromString('abc,w_,__,_100,w100.png');
        $this->assertEquals('abc.png', $params->toString());
    }

    public function testSkipWidthHeightParameters()
    {
        $params = new Parameters();
        $config = new Config(array(
            'max_width' => 200,
            'max_height' => 100,
        ));
        $params->setConfig($config);
        $params->fromString('filename,w_200,h_100.jpg');
        $this->assertEquals('filename.jpg', $params->toString());


        $params = new Parameters();
        $params->setConfig($config);
        $params->setImageSize(100, 50);
        $params->fromString('filename,w_100,h_50.jpg');
        $this->assertEquals('filename.jpg', $params->toString());
        $params->fromString('filename,w_200,h_300.jpg');
        $this->assertEquals('filename.jpg', $params->toString());
        $params->fromString('filename,w_99,h_49.jpg');
        $this->assertEquals('filename,h_49,w_99.jpg', $params->toString());

        $params = new Parameters();
        $params->fromString('filename,c_fill,w_100.jpg');
        $this->assertEquals('filename,w_100.jpg', $params->toString());

        $params = new Parameters();
        $params->fromString('filename,c_fill,h_100.jpg');
        $this->assertEquals('filename,h_100.jpg', $params->toString());

        $params = new Parameters();
        $params->fromString('filename,c_fill,w_100,h_50.jpg');
        $this->assertEquals('filename,c_fill,h_50,w_100.jpg', $params->toString());
    }


    public function testSkipCropParameters()
    {
        $params = new Parameters();
        $params->fromString('filename,y_100,x_10.jpg');
        $this->assertEquals('filename.jpg', $params->toString());

        $params = new Parameters();
        $params->fromString('filename,c_fill,y_100,x_10.jpg');
        $this->assertEquals('filename.jpg', $params->toString());

        $params = new Parameters();
        $params->fromString('filename,c_100,y_100,x_10.jpg');
        $this->assertEquals('filename,c_100,x_10,y_100.jpg', $params->toString());
    }
}
