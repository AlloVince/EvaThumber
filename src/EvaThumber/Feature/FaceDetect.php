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

namespace EvaThumber\Feature;

use Imagine\Image\ImageInterface;
use EvaThumber\Filesystem;


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
        $cascadeFile = __DIR__ . '/../../../data/haarcascades/haarcascade_frontalface_alt.xml';
        if(file_exists($cascadeFile) && is_readable($cascadeFile)){
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
        $pb = $this->createProcessBuilder(array('' . $this->facedetectBin));

        $uniq = uniqid();
        $input = sys_get_temp_dir() . '/evathumber_facedetect_' . $uniq . '_in.png';
        $pb->add($input);
        $image->save($input);

        $output = sys_get_temp_dir() . '/evathumber_facedetect_' . $uniq . '_out.json';
        $pb->add($output);

        $pb->add($this->cascade);

        $proc = $pb->getProcess();
        $code = $proc->run();

        if (0 < $code) {
            //unlink($input);
            //return false;
        }

        $res = array();
        if(file_exists($output)){
            $res = json_decode(file_get_contents($output));
            @unlink($output);
        }
        @unlink($input);
        return $res;
    }
}

