<?php

namespace EvaThumber\Feature;

use Imagine\Image\ImageInterface;


class FaceDetect extends AbstractProcess implements FeatureInterface
{
    private $facedetectBin;
    private $cascade;

    public function getCascade()
    {
        return $this->cascade;
    }

    public function setCascade($cascade)
    {
        $this->cascade = $cascade;
        return $this;
    }

    public static function isSupport()
    {
        if(file_exists(__DIR__ . '/../../../data/haarcascades/haarcascade_frontalface_alt.xml')){
            return true;
        }
        return false;
    }

    /**
     * Constructor.
     *
     * @param string $pngoutBin Path to the pngout binary
     */
    public function __construct($facedetectBin = 'opencv.py', $cascade = null)
    {
        $this->facedetectBin = $facedetectBin;
        $this->cascade = $cascade;
    }


    public function filterDump(ImageInterface $image)
    {
        $pb = $this->createProcessBuilder(array('python ' . $this->facedetectBin));

        $uniq = uniqid();
        $input = sys_get_temp_dir() . '/evathumber_facedetect_' . $uniq . '_in.png';
        $pb->add($input);
        $image->save($input);

        $output = sys_get_temp_dir() . '/evathumber_facedetect_' . $uniq . '_out.json';
        $pb->add($output);

        $proc = $pb->getProcess();
        $code = $proc->run();

        if (0 < $code) {
            unlink($input);
            return false;
            //return $image;
        }


        unlink($input);
        return $output;
    }
}

