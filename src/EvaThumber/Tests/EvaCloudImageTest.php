<?php
require_once "PHPUnit/Autoload.php";
require_once "EvaCloudImage.php";

class EvaCloudImageTest extends PHPUnit_Framework_TestCase
{
    protected $evaCloudImage;
    protected $testUrl = 'http://localhost/EvaCloudImage/thumb/demo.jpg';
    protected $testConfig;

    protected function setUp() {
        $this->testConfig = array(
            'debug' => false,
            'error_redirect' => 'http://avnpc.com/pages/evacloudimage',
            'libPath' => __DIR__ . '/lib',
            'sourceRootPath' => __DIR__ . '/upload',
            'thumbFileRootPath' => __DIR__ . '/thumb',
            'thumbUrlRootPath' => __DIR__ . '/..' ,
            'saveImage' => false,
        );
        $this->evaCloudImage = new EvaCloudImage($this->testUrl, $this->testConfig);
    }


    public function testUrl()
    {
        $this->evaCloudImage->setUrl($this->testUrl);
        $this->assertEquals($this->testUrl, $this->evaCloudImage->getUrl());
    }

    public function testImageNameArgs()
    {
        $this->evaCloudImage->setUrl($this->testUrl);
        $this->assertEquals('demo.jpg', $this->evaCloudImage->getSourceImageName());

        $this->evaCloudImage->setSourceImageName('');
        $this->evaCloudImage->setUrl('http://localhost/EvaCloudImage/thumb/demo,w_100.jpg');
        $this->assertEquals('demo.jpg', $this->evaCloudImage->getSourceImageName());
        $this->assertEquals(array('w_100'), $this->evaCloudImage->getImageNameArgs());


        $this->evaCloudImage->setSourceImageName('');
        $this->evaCloudImage->setUrl('http://localhost/EvaCloudImage/thumb/demo,w_100,h_20.jpg');
        $this->assertEquals('demo.jpg', $this->evaCloudImage->getSourceImageName());
        $this->assertEquals(array('w_100', 'h_20'), $this->evaCloudImage->getImageNameArgs());

        $this->evaCloudImage->setSourceImageName('');
        $this->evaCloudImage->setUrl('http://localhost/EvaCloudImage/thumb/demo,w_100,w_20.jpg');
        $this->assertEquals('demo.jpg', $this->evaCloudImage->getSourceImageName());
        $this->assertEquals(array('w_100','w_20'), $this->evaCloudImage->getImageNameArgs());

        $this->evaCloudImage->setSourceImageName('');
        $this->evaCloudImage->setUrl('http://localhost/EvaCloudImage/thumb/demo,abc,def.jpg');
        $this->assertEquals('demo.jpg', $this->evaCloudImage->getSourceImageName());
        $this->assertEquals(array('abc','def'), $this->evaCloudImage->getImageNameArgs());

        $this->evaCloudImage->setSourceImageName('');
        $this->evaCloudImage->setUrl('http://localhost/EvaCloudImage/thumb/demo,,,,.jpg');
        $this->assertEquals('demo.jpg', $this->evaCloudImage->getSourceImageName());
        $this->assertEquals(array(), $this->evaCloudImage->getImageNameArgs());


        $this->evaCloudImage->setSourceImageName('');
        $this->evaCloudImage->setUrl('http://localhost/EvaCloudImage/thumb/demo,,abc,,.jpg');
        $this->assertEquals('demo.jpg', $this->evaCloudImage->getSourceImageName());
        $this->assertEquals(array('abc'), $this->evaCloudImage->getImageNameArgs());
    }


    public function testArgsToParameters()
    {
        $params = $this->evaCloudImage->getTransferParameters();
        $this->evaCloudImage->setTransferParametersMerged(true);
        $this->evaCloudImage->setImageNameArgs(array());
        $this->assertEquals($params, $this->evaCloudImage->getTransferParameters());


        $this->evaCloudImage->setTransferParametersMerged(false);
        $this->evaCloudImage->setImageNameArgs(array(
            'w_100',
            'h_20',
            'q_10',
            'r_50',
            'x_100',
            'y_200',
            'c_100',
            'g_200',
        ));
        $params = $this->evaCloudImage->getTransferParameters();
        $this->assertEquals('100', $params['width']);
        $this->assertEquals('20', $params['height']);
        $this->assertEquals('10', $params['quality']);
        $this->assertEquals('50', $params['rotate']);
        $this->assertEquals('100', $params['x']);
        $this->assertEquals('200', $params['y']);
        $this->assertEquals('100', $params['crop']);
        $this->assertEquals('200', $params['gravity']);


        $this->evaCloudImage = new EvaCloudImage($this->testUrl, $this->testConfig);
        $this->evaCloudImage->setImageNameArgs(array(
            'w_',
            'h_abc_',
            'q',
            'abc',
            '_abc_',
            '__abc_',
            'r_100',
            'r_200',
        ));
        $params = $this->evaCloudImage->getTransferParameters();
        $this->assertEquals(null, $params['width']);
        $this->assertEquals('abc_', $params['height']);
        $this->assertEquals('200', $params['rotate']);
        $this->assertEquals(8, count($params));
    }


    public function testUniqueName()
    {
        $this->evaCloudImage = new EvaCloudImage($this->testUrl, $this->testConfig);
        $this->evaCloudImage->setImageNameArgs(array(
            'w_100',
            'h_20',
            'q_10',
            'r_50',
            'x_100',
            'y_200',
            'c_200',
            'g_100',
        ));
        $params = $this->evaCloudImage->getUniqueParameters();
        $this->assertEquals(100, $params['width']);
        $this->assertEquals(20, $params['height']);
        $this->assertEquals(10, $params['quality']);
        $this->assertEquals(50, $params['rotate']);
        $this->assertEquals(100, $params['x']);
        $this->assertEquals(200, $params['crop']);
        $this->assertEquals(100, $params['gravity']);


        $this->evaCloudImage = new EvaCloudImage($this->testUrl, $this->testConfig);
        $this->evaCloudImage->setImageNameArgs(array(
            'w_100',
            'h_0.2',
        ));
        $params = $this->evaCloudImage->getUniqueParameters();
        $this->assertEquals(100, $params['width']);
        $this->assertEquals(0, $params['height']);


        $this->evaCloudImage = new EvaCloudImage($this->testUrl, $this->testConfig);
        $this->evaCloudImage->setImageNameArgs(array(
            'w_0.1',
        ));
        $params = $this->evaCloudImage->getUniqueParameters();
        $this->assertEquals(0.1, $params['width']);
        $this->assertEquals(null, $params['height']);


        $this->evaCloudImage = new EvaCloudImage($this->testUrl, $this->testConfig);
        $this->evaCloudImage->setImageNameArgs(array(
            'w_0.1',
            'h_0.2',
        ));
        $params = $this->evaCloudImage->getUniqueParameters();
        $this->assertEquals(0.2, $params['width']);
        $this->assertEquals(null, $params['height']);

        $this->evaCloudImage = new EvaCloudImage($this->testUrl, $this->testConfig);
        $this->evaCloudImage->setImageNameArgs(array(
            'w_0.2',
            'h_0.1',
        ));
        $params = $this->evaCloudImage->getUniqueParameters();
        $this->assertEquals(0.2, $params['width']);
        $this->assertEquals(null, $params['height']);

        $this->evaCloudImage = new EvaCloudImage($this->testUrl, $this->testConfig);
        $this->evaCloudImage->setImageNameArgs(array(
            'c_abc',
            'g_200',
            'q_abc',
        ));
        $params = $this->evaCloudImage->getUniqueParameters();
        $this->assertEquals(null, $params['crop']);
        $this->assertEquals(null, $params['gravity']);
        $this->assertEquals(null, $params['quality']);


        $this->evaCloudImage = new EvaCloudImage($this->testUrl, $this->testConfig);
        $this->evaCloudImage->setImageNameArgs(array(
            'c_0',
            'x_100',
            'y_100',
        ));
        $params = $this->evaCloudImage->getUniqueParameters();
        $this->assertEquals(null, $params['crop']);
        $this->assertEquals(null, $params['x']);
        $this->assertEquals(null, $params['y']);

        $this->evaCloudImage = new EvaCloudImage($this->testUrl, $this->testConfig);
        $this->evaCloudImage->setImageNameArgs(array(
            'c_fill',
            'w_100',
        ));
        $params = $this->evaCloudImage->getUniqueParameters();
        $this->assertEquals(null, $params['crop']);
        $this->assertEquals(100, $params['width']);
    }


    public function testUniqueNameString()
    {
        $this->evaCloudImage = new EvaCloudImage($this->testUrl, $this->testConfig);
        $this->assertEquals($this->evaCloudImage->getSourceImageName(), $this->evaCloudImage->getUniqueTargetImageName());

        $this->evaCloudImage = new EvaCloudImage($this->testUrl, $this->testConfig);
        $this->evaCloudImage->getSourceImageName();
        $this->evaCloudImage->setImageNameArgs(array(
            'w_100',
            'h_20',
            'q_10',
            'r_50',
            'x_100',
            'y_200',
            'c_200',
            'g_100',
        ));
        $name = $this->evaCloudImage->getUniqueTargetImageName();
        $this->assertEquals('demo,c_200,g_100,h_20,q_10,r_50,w_100,x_100,y_200.jpg', $name);
    }

    public function testStaticUrl()
    {
        $url = EvaCloudImage::url('http://evacloudimage.avnpc.com/thumb/demo.jpg', array('w_100','h_200'));
        $this->assertEquals('http://evacloudimage.avnpc.com/thumb/demo,h_200,w_100.jpg', $url);
    }
}
