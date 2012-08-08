EvaCloudImage - light-weight url based image transformation php library
================================================================

EvaCloudImage is a url based image transformation php library.

EvaCloudImage allow you to easily transform your images to any required dimension. EvaCloudImage support optimizing, resizing or cropping the images by just change few url letters.

Shadow Pretect
--------------

Sometimes we don't want to expose images source url, EvaCloudImage allow you create shadow image to pretect source file:

 - Image source file : [http://evacloudimage.avnpc.com/upload/demo.jpg](http://evacloudimage.avnpc.com/upload/demo.jpg)
 - Shadow image : [http://evacloudimage.avnpc.com/thumb/demo.jpg](http://evacloudimage.avnpc.com/thumb/demo.jpg), use this url in your website, visitor will not know source file url.

Shadow pretect support multi-level directory structure, if cache enabled, all folders in URL will be created.

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

Crop use '*c*' as parameter, under crop mode, you could pass an integer value for croppin from the center of the image.

    http://evacloudimage.avnpc.com/thumb/demo,c_100.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,c_100.jpg)

Cropping fixed coordinates of image, by using the 'x' and 'y' parameters. Also the width and height parameters is required.

    http://evacloudimage.avnpc.com/thumb/demo,c_crop,h_200,w_100,x_80,y_10.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,c_crop,h_200,w_100,x_80,y_10.jpg)


Rotate
-----------------

The rotate parameter is '*r*' for rotate images, by passing an integer value could rotate image clockwise:

For example, rotate the demo image by 90 degress clockwise by setting the '*r*' parameter to *90*:

    http://evacloudimage.avnpc.com/thumb/demo,h_200,r_90.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,h_200,r_90.jpg)


JPG Quality
-----------------

The quality parameter is '*q*' for changing compression quality, default quality is 100:

    http://evacloudimage.avnpc.com/thumb/demo,h_200,q_30.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,h_200,q_30.jpg)

Caching
-------




Installation
------------

###Requirements

1. PHP version must greater than 5.3.0
2. GD 2.0+
3. Mod rewrite module enabled in webserver.

###Download

Ready to get started? Well then, download the [latest release](https://github.com/AlloVince/EvaCloudImage/zipball/master)!


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


Tech
----

EvaCloudImage uses below open source projects to work properly:

 - [PHP Thumb](https://github.com/masterexploder/PHPThumb) : thumbnail generation library;
 - [Cloudinary](http://cloudinary.com/) : API design is almost as same as Cloudinary;

License
-------

EvaCloudImage is a independent components of [EvaEngine](https://github.com/AlloVince/eva-engine), which released under the [New BSD License](http://framework.zend.com/license/new-bsd). 

Thanks to
---------
Demo image is from [Рыбачка](http://nzakonova.35photo.ru/photo_391467/), great shot!
