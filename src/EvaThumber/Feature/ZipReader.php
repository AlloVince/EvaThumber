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

use ZipArchive;
use EvaThumber\Exception;

class ZipReader implements FeatureInterface
{
    public static function isSupport()
    {
        if(false === class_exists('ZipArchive')){
            return false;
        }

        return true;
    }

    public static function getStreamPath($filePath, $zipfile, $fileEncoding = null)
    {
        $filePath = ltrim($filePath, '/');
        $filePath = $fileEncoding ? iconv('UTF-8', $fileEncoding, $filePath) : $filePath;
        return 'zip://' . $zipfile . '#' . $filePath;
    }

    public static function parseStreamPath($steamPath)
    {
        preg_match('/zip:\/\/([^#]+)#(.+)/', $steamPath, $matches); 
        if(count($matches) < 3){
            return false;
        }
        return array(
            'zipfile' => $matches[1],
            'innerpath' => $matches[2],
        );
    }

    public static function glob($globPath, $fileEncoding = 'UTF-8')
    {
        $zipStream = self::parseStreamPath($globPath);
        if(!$zipStream){
            return array();
        }

        $zipfile = $zipStream['zipfile'];
        $innerpath = $zipStream['innerpath'];
        $zip = new ZipArchive();
        $res = $zip->open($zipfile);

        if(!$res){
            return array();
        }

        $files = array();
        $i = 0;
        $numFiles = $zip->numFiles;
        for($i; $i < $numFiles; $i++){
            $file = $zip->statIndex($i);
            $filename = $file['name'];
            $filename = substr($filename, 0, strrpos($filename, '.')) . '.*';
            if($innerpath === $filename){
                $files[] = $file;
            }
        }
        return $files;
    }

    public static function read($filePath, $zipfile, $fileEncoding = 'UTF-8')
    {
        $zip = new ZipArchive();
        $filePath = ltrim($filePath, '/');
        $filePath = $fileEncoding ? iconv('UTF-8', $fileEncoding, $filePath) : $filePath;
        $res = $zip->open($zipfile);

        $file = array();
        if ($res) {
            $i = 0;
            $numFiles = $zip->numFiles;
            for($i; $i < $numFiles; $i++){
                $file = $zip->statIndex($i);
                if($file['name'] == $filePath){
                    break;
                }
                $file = array();
            }
        }
        
        $sourefile = '';
        if ($file) {
            $fp = $zip->getStream($file['name']);
            if(!$fp){
                throw new Exception\IOException(sprintf(
                    'Not able to read zip inner file %s', iconv($fileEncoding, "UTF-8", $file['name'])
                )); 
            }
            while (!feof($fp)) {
                $sourefile .= fread($fp, 2);
            }
            fclose($fp);
        }
        $zip->close();

        return $sourefile;
    }
}
