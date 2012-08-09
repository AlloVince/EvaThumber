<?php
require_once "PHPUnit/Autoload.php";
require_once "EvaCloudImage.php";

class EvaCloudImageTest extends PHPUnit_Framework_TestCase
{
    protected $evaCloudImage;
    protected $testUrl = 'http://localhost/EvaCloudImage/thumb/demo.jpg';

    protected function setUp() {
        $config = array(
            'debug' => false,
            'error_redirect' => 'http://avnpc.com/pages/evacloudimage',
            'libPath' => __DIR__ . '/lib',
            'sourceRootPath' => __DIR__ . '/upload',
            'thumbFileRootPath' => __DIR__ . '/thumb',
            'thumbUrlRootPath' => __DIR__ . '/..' ,
            'saveImage' => false,
        );
        $this->evaCloudImage = new EvaCloudImage($this->testUrl, $config);
    }

    public function testUrl()
    {
        $this->evaCloudImage->setUrl($this->testUrl);
        $this->assertEquals($this->testUrl, $this->evaCloudImage->getUrl());
    }

}
