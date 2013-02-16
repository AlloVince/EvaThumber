[EvaCloudImage](http://avnpc.com/pages/evacloudimage) 是一个基于URL生成缩略图的轻量级PHP库，支持缩放/旋转/截取等多种缩略图生成方式，而仅仅需要通过简单更改图片的URL。

EvaCloudImage is a light-weight & opensource url based image transformation php library. See [English version document](https://github.com/AlloVince/EvaCloudImage).

EvaCloudImage的源代码存放于[Github](https://github.com/AlloVince/EvaCloudImage)，完全开源，欢迎[Fork](https://github.com/AlloVince/EvaCloudImage)或[关注我](https://github.com/AlloVince)。

EvaCloudImage的功能包括：

影子模式
--------------

很多时候我们不希望暴露原图片的地址，此时可以通过EvaCloudImage自动生成原图片的影子图片，保护原图片URL不被泄露，比如

 - 原图片地址为 : [http://evacloudimage.avnpc.com/upload/demo.jpg](http://evacloudimage.avnpc.com/upload/demo.jpg)
 - 影子图片地址为 : [http://evacloudimage.avnpc.com/thumb/demo.jpg](http://evacloudimage.avnpc.com/thumb/demo.jpg)，在网站中只需要公布影子图片即可

假如原图片位于多级的树形目录下，影子图片也会保持与原图片同样的目录结构，从原切换到影子只需要更改域名或者根目录。

图片缩放
-----------------

这里是[原图](http://evacloudimage.avnpc.com/upload/demo.jpg): 

    http://evacloudimage.avnpc.com/upload/demo.jpg

EvaCloudImage只需要更改影子图片的URL即可实现缩放，只需要图片的文件名末尾加入以逗号分隔的参数即可：

###根据宽度缩放:

'*w*'参数是Width的缩写，可以控制图片按宽度缩放。下面的URL会生成一张300px宽的图片：

    http://evacloudimage.avnpc.com/thumb/demo,w_300.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,w_300.jpg)

###根据高度缩放:

同理通过更改'*h*'（Height），根据高度缩放图片：

    http://evacloudimage.avnpc.com/thumb/demo,h_150.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,h_150.jpg)

###按百分比缩放:

当w或h为小数时，图片会按照百分比缩放，比如w_0.4会将图片缩放至原尺寸的40%：

    http://evacloudimage.avnpc.com/thumb/demo,w_0.4.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,w_0.4.jpg)

注意：

 - 当w与h既有整数又有小数时，以整数为准
 - 当w与h同时为小数时，以w为准

图片剪裁
----

使用'*c*'参数（Crop）可以剪裁图片，比如c_100会从图片的中心位置截取出一张100px的缩略图。

    http://evacloudimage.avnpc.com/thumb/demo,c_100.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,c_100.jpg)

'*g*'参数（gravity）代表剪裁范围或高度，需要配合c参数一起使用。比如下例，代表从图片中心位置剪裁一张100px*200px的缩略图。

    http://evacloudimage.avnpc.com/thumb/demo,c_200,g_100.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,c_200,g_100.jpg)

如果想要指定剪裁的精确位置，需要用'x'和'y'参数指定起点坐标，比如下面的例子，代表以距离图片左边80px，上边10px为起点，剪裁一张100px*200px的图片。

    http://evacloudimage.avnpc.com/thumb/demo,c_100,g_200,x_80,y_10.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,c_100,g_200,x_80,y_10.jpg)

图片的剪裁与缩放可以混用，EvaCloudImage始终会先进行剪裁，然后再对剪裁后的图片缩放。

    http://evacloudimage.avnpc.com/thumb/demo,c_100,g_200,w_50.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,c_100,g_200,w_50.jpg)

###填充模式

在实际使用中，我们经常会遇到这样的场景：需要截取并缩放图片以适应网页布局，此时我们可以使用剪裁中的填充模式，在填充模式下，需要指定剪裁参数为c_fill，同时设定填充的宽度与高度，然后可以得到一张完全吻合设定尺寸，同时经过缩放与剪裁处理的图片。

    http://evacloudimage.avnpc.com/thumb/demo,c_fill,w_250,h_50.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,c_fill,w_250,h_50.jpg)

在填充模式下还可以设定剪裁范围，允许的剪裁范围包括'top'（从上方）, 'bottom'（从下方）, 'left'（从左）， 'right'（从右）。

    http://evacloudimage.avnpc.com/thumb/demo,c_fill,g_top,w_250,h_60.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,c_fill,g_top,w_250,h_60.jpg)

旋转
-----------------

旋转参数为'*r*' (rotate) ，传递一个数字作为图片旋转的角度，比如让图片按照逆时针旋转90度：

    http://evacloudimage.avnpc.com/thumb/demo,h_200,r_90.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,h_200,r_90.jpg)


JPG图片压缩质量
-----------------

通过'*q*'(quality)可以指定jpg图片的压缩质量，默认为100:

    http://evacloudimage.avnpc.com/thumb/demo,h_200,q_10.jpg

![EvaCloudImage Resized Image](http://evacloudimage.avnpc.com/thumb/demo,h_200,q_10.jpg)



下载
------------


下载 [最新版本的EvaCloudImage](https://github.com/AlloVince/EvaCloudImage/zipball/master)，解压即可使用。

安装
------------

###1. 环境需求

1. PHP 版本大于 5.3.0
2. 以安装GD 2.0+
3. 服务器已经开启Url Re-write模块


###2. 对安装目录开启Url Re-write

####Apache设置：

如果服务器为Apache并且已经开启[mod_rewrite](http://httpd.apache.org/docs/current/mod/mod_rewrite.html)模块，则无需任何设置，重写规则已经写入.htaccess文件。

####Nginx

请参考以下配置调整路径

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

###3. 配置文件

编辑源代码中的config.inc.php文件：

    array(
        'libPath' => __DIR__ . '/lib',  //依赖库的存放路径，一般无需更改
        'sourceRootPath' => __DIR__ . '/upload',  //原图片的存放路径，需要读取权限
        'thumbFileRootPath' => __DIR__ . '/thumb', //缩略图的存放路径，需要读写权限
        'thumbUrlRootPath' => __DIR__, //缩略域名绑定的根目录
        'saveImage' => false,  //如果开启，所有缩略图会自动保存，在正式环境推荐打开。
    );


相关技术
----

EvaCloudImage 使用了以下的开源技术：

 - [PHP Thumb](https://github.com/masterexploder/PHPThumb) : 好用的缩略图生成库;
 - [Cloudinary](http://cloudinary.com/) : API设计参考了著名云服务Cloudinary;

许可证
-------

EvaCloudImage 是 [EvaEngine](https://github.com/AlloVince/eva-engine)项目的一个前端组件，基于[New BSD License](http://framework.zend.com/license/new-bsd)发布，简单说，你可以将EvaCloudImage用与任何商业或非商业项目中，可以自由更改EvaCloudImage的源代码，惟一做的是保留源代码中的作者信息。

感谢
---------
实例图片来自 [Рыбачка](http://nzakonova.35photo.ru/photo_391467/)

更新
--------

- 2012/08/14 增加填充模式

