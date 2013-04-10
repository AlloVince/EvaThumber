EvaCloudImage - Light-weight url based image transformation php library
================================================================

Master: [![Build Status](https://secure.travis-ci.org/AlloVince/EvaThumber.png?branch=master)](http://travis-ci.org/AlloVince/EvaThumber)

EvaCloudImage is a url based image transformation php library.

EvaCloudImage allow you to easily transform your images to any required dimension. EvaCloudImage support optimizing, resizing or cropping the images by just change few url letters.

Shadow Protection
-----------------

Sometimes we don't want to expose images source url, EvaCloudImage allow you create shadow image to protect source file:

 - Image source file : [http://evacloudimage.avnpc.com/upload/demo.jpg](http://evacloudimage.avnpc.com/upload/demo.jpg)
 - Shadow image : [http://evacloudimage.avnpc.com/thumb/demo.jpg](http://evacloudimage.avnpc.com/thumb/demo.jpg), use this url in your website, visitor will not know source file url.

Shadow protection support multi-level directory structure, if cache enabled, all folders in URL will be created.

Resize Dimensions
-----------------

Here is the [original image](http://evacloudimage.avnpc.com/upload/demo.jpg): 

    http://evacloudimage.avnpc.com/upload/demo.jpg

EvaCloudImage could resize the image by simply passing in the width and height parameters in URL. 

###Resize by width:

The following URL points to a 300px width dynamically created image, pass the '*w*' parameter by '*w_300*':

    http://evacloudimage.avnpc.com/thumb/demo,w_300.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,w_300.jpg)

###Resize by height:

The following URL points to a 150px height dynamically created image, pass the '*h*' parameter by '*h_150*':

    http://evacloudimage.avnpc.com/thumb/demo,h_150.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,h_150.jpg)

###Resize by percent:

By passing integer values for resizing images by fixed width and height. You can also change the dimension of an image using percents. 

For example, resizing the demo image to *40%* of its original size is done by setting the '*width*' parameter to *0.4*:

    http://evacloudimage.avnpc.com/thumb/demo,w_0.4.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,w_0.4.jpg)


Crop
----



Crop use '*c*' as parameter, under crop mode, you could pass an integer value for cropping from the center of the image.

    http://evacloudimage.avnpc.com/thumb/demo,c_100.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,c_100.jpg)

'*g*' parameter is shorten for *gravity* which assist Crop mode, this paramter defined which part of the image to take, or crop height.

Below example use both crop and gravity will get a 100px*200px image cropping from center of the image

    http://evacloudimage.avnpc.com/thumb/demo,c_200,g_100.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,c_200,g_100.jpg)

Cropping fixed coordinates of image, by using the 'x' and 'y' parameters. Also the width and height parameters is required.

    http://evacloudimage.avnpc.com/thumb/demo,c_100,g_200,x_80,y_10.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,c_100,g_200,x_80,y_10.jpg)

EvaCloudImage support both cropping and resizing on same time, below example will crop image to 100*200 and resize to 50px width. Resizing is always after cropping.

    http://evacloudimage.avnpc.com/thumb/demo,c_100,g_200,w_50.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,c_100,g_200,w_50.jpg)

###Fill Mode

We always use resized images with exact given dimensions to meet web page layouts, EvaCloudImage support set crop to 'fill' by 'c_fill', then set the image width and height will get the part of the image which fills the given dimensions:

    http://evacloudimage.avnpc.com/thumb/demo,c_fill,w_250,h_50.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,c_fill,w_250,h_50.jpg)

Under fill mode, change gravity to '*top*', '*bottom*', '*left*', '*right*' will change crop quadrants.

    http://evacloudimage.avnpc.com/thumb/demo,c_fill,g_top,w_250,h_60.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,c_fill,g_top,w_250,h_60.jpg)

Rotate
-----------------

The rotate parameter is '*r*' for rotate images, by passing an integer value could rotate image clockwise:

For example, rotate the demo image by 90 degress clockwise by setting the '*r*' parameter to *90*:

    http://evacloudimage.avnpc.com/thumb/demo,h_200,r_90.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,h_200,r_90.jpg)


JPG Quality
-----------------

The quality parameter is '*q*' for changing compression quality, default quality is 100:

    http://evacloudimage.avnpc.com/thumb/demo,h_200,q_10.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,h_200,q_10.jpg)



Download
------------

Ready to get started? Well then, download the [latest release](https://github.com/AlloVince/EvaCloudImage/zipball/master)!

Installation
------------

###Requirements

1. PHP version must greater than 5.3.0
2. GD 2.0+
3. Mod-rewrite module enabled in webserver.


###Enable Url Re-write

####Apache Setting

Apache setting is already wroten in .htaccess.

Please make sure the [mod_rewrite](http://httpd.apache.org/docs/current/mod/mod_rewrite.html) Module is already enabled.

####Nginx

Config as below

    server {
            listen   80;
            server_name  evacloudimage.avnpc.com;
            location / {
                    root  /usr/www/EvaCloudImage/;
                    index index.php index.html index.htm;
                    if (!-e $request_filename){
                       rewrite ^/(.*)$ /index.php?$1& last;
                    }
            }
            location ~ \.php$ {
                    include fastcgi_params;
                    fastcgi_pass   127.0.0.1:9000;
                    fastcgi_index  index.php;
                    fastcgi_param  SCRIPT_FILENAME  /usr/www/EvaCloudImage/$fastcgi_script_name;
            }
    }

###Configuration

Edit config.inc.php to change image paths.

    array(
        'libPath' => __DIR__ . '/lib',  //PHPthumb library path
        'sourceRootPath' => __DIR__ . '/upload',  //original image save path, require path read permission
        'thumbFileRootPath' => __DIR__ . '/thumb', //resized thumbnails save path, require read and write permission
        'thumbUrlRootPath' => __DIR__, //thumbnails url root path, require read and write permission
        'saveImage' => false,  //if true, thumbnails will be created and auto save as same directory structure as original images
    );


Tech
----

EvaCloudImage uses below open source projects to work properly:

 - [PHP Thumb](https://github.com/masterexploder/PHPThumb) : thumbnail generation library;
 - [Cloudinary](http://cloudinary.com/) : API design is almost as same as Cloudinary;

License
-------

EvaCloudImage is a independent components of [EvaEngine](https://github.com/AlloVince/eva-engine), which released under the [New BSD License](http://framework.zend.com/license/new-bsd). 

TODO
----

1. Add ImageMagick support.
2. Image size limit mode
3. Better error handler
4. Face detect.
5. Image effect filters
6. Add allow image sizes

Thanks to
---------
Demo image is from [Рыбачка](http://nzakonova.35photo.ru/photo_391467/), great shot!


