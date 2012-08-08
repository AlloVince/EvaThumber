EvaCloudImage - light-weight url based image transformation php library
================================================================

EvaCloudImage is a url based image transformation php library.

EvaCloudImage allow you to easily transform your images to any required dimension. EvaCloudImage support optimizing, resizing or cropping the images by just change few url letters.

Resize Dimensions
-----------------


Installation
------------

###Nginx

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



Tech
----

EvaCloudImage uses below open source projects to work properly:

 - [PHP Thumb](https://github.com/masterexploder/PHPThumb) : thumbnail generation library;
 - [Cloudinary](http://cloudinary.com/) : API design is almost as same as Cloudinary;


Thanks to
---------
Demo image is from [Рыбачка](http://nzakonova.35photo.ru/photo_391467/), great shot!



