#!/usr/bin/python
"""
This program is demonstration for face and object detection using haar-like features.
The program finds faces in a camera image or video stream and displays a red box around them.

Original C implementation by:  ?
Python implementation by: Roman Stanchak, James Bowman
"""
import sys
import cv2.cv as cv
import os
import json
from optparse import OptionParser

# Parameters for haar detection
# From the API:
# The default parameters (scale_factor=2, min_neighbors=3, flags=0) are tuned
# for accurate yet slow object detection. For a faster operation on real video
# images the settings are:
# scale_factor=1.2, min_neighbors=2, flags=CV_HAAR_DO_CANNY_PRUNING,
# min_size=<minimum possible face size

min_size = (20, 20)
image_scale = 1
haar_scale = 1.2
min_neighbors = 2
haar_flags = 0
sys_path = os.getcwd()

def detect_and_draw(img, out_put, cascade):
    img = cv.LoadImage(img, 1)
    res = {'faces' : 0, 'data' : []}
    # allocate temporary images
    gray = cv.CreateImage((img.width,img.height), 8, 1)
    small_img = cv.CreateImage((cv.Round(img.width / image_scale),
                   cv.Round (img.height / image_scale)), 8, 1)

    # convert color input image to grayscale
    cv.CvtColor(img, gray, cv.CV_BGR2GRAY)

    # scale input image for faster processing
    cv.Resize(gray, small_img, cv.CV_INTER_LINEAR)

    cv.EqualizeHist(small_img, small_img)

    if(cascade):
        t = cv.GetTickCount()
        cascade = cv.Load(cascade)
        faces = cv.HaarDetectObjects(small_img, cascade, cv.CreateMemStorage(0),
                                     haar_scale, min_neighbors, haar_flags, min_size)
        t = cv.GetTickCount() - t
        print "detection time = %gms" % (t/(cv.GetTickFrequency()*1000.))
        if faces:
            i = 0
            for ((x, y, w, h), n) in faces:
                i = i + 1
                res['data'].append({
                    'x' : x,
                    'y' : y,
                    'w' : w,
                    'h' : h
                    })
                pt1 = (int(x * image_scale), int(y * image_scale))
                pt2 = (int((x + w) * image_scale), int((y + h) * image_scale))
                cv.Rectangle(img, pt1, pt2, cv.RGB(255, 0, 0), 3, 8, 0)
            res['faces'] = i
        print res
        #with open(sys_path + r'/res.json', 'w') as outfile:
        with open(out_put, 'w') as outfile:
            json.dump(res, outfile)

    cv.SaveImage(sys_path + r'/debug.jpg', img);

#image_path = sys_path + r'/abc.jpg'
#save_path = sys_path + r'/res.jpg'
#print sys_path
#print image_path
#print xml_path
#detect_and_draw(image_path, save_path, xml_path)
#xml_path = r'/opt/htdocs/EvaCloudImage/data/haarcascades/haarcascade_frontalface_alt.xml'

if __name__ == '__main__':

    parser = OptionParser(usage = "usage: %prog [options] [inputfile] [outputfile] [xmlpath]")
    #parser.add_option("-c", "--cascade", action="store", dest="cascade", type="str", help="Haar cascade file, default %default", default = xml_path)
    (options, args) = parser.parse_args()

    if len(args) != 3:
        parser.print_help()
        sys.exit(1)

    image_path = args[0]
    save_path = args[1]
    xml_path = args[2]
    detect_and_draw(image_path, save_path, xml_path)
