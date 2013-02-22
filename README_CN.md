[EvaThumber](http://avnpc.com/pages/EvaThumber) 是一个基于URL的轻量级图片处理库，支持缩放/旋转/截取/滤镜等多种常用图片处理，可以设置全局水印，所有处理仅仅需要通过更改图片的URL。

EvaThumber基于PHP实现，可以一键安装在任何主流系统，由于基于URL实现接口，所以其他编程语言也可以使用EvaThumber作为前端组件。

EvaThumber is a light-weight & opensource url based image transformation php library. See [English version document](https://github.com/AlloVince/EvaThumber).

EvaThumber的源代码存放于[Github](https://github.com/AlloVince/EvaThumber)，完全开源，欢迎[Fork](https://github.com/AlloVince/EvaThumber)或[关注我](https://github.com/AlloVince)。

下面是一个实例：

原图：

EvaThumber的处理：裁剪为宽200，高200，加黑白滤镜，压缩质量70，输出为png格式



为什么用EvaThumber
===================

一切基于URL，人人可用
---------------------


所见即所得，前端人员无痛调试
----------------------------


项目前期大量图片素材自动获取
---------------------------

同时支持GD/Imagick/Gmagick
----------------------------



基本功能
========



影子模式
--------------

很多时候我们不希望暴露原图片的地址，此时可以通过EvaThumber自动生成原图片的影子图片，保护原图片URL不被泄露，比如

 - 原图片地址为 : [http://EvaThumber.avnpc.com/upload/demo.jpg](http://EvaThumber.avnpc.com/upload/demo.jpg)
 - 影子图片地址为 : [http://EvaThumber.avnpc.com/thumb/demo.jpg](http://EvaThumber.avnpc.com/thumb/demo.jpg)，在网站中只需要公布影子图片即可

假如原图片位于多级的树形目录下，影子图片也会保持与原图片同样的目录结构，从原切换到影子只需要更改域名或者根目录。

###图片尺寸限制



图片格式转换
-------------

图片缩放
-----------------

这里是[原图](http://EvaThumber.avnpc.com/upload/demo.jpg): 

    http://EvaThumber.avnpc.com/upload/demo.jpg

EvaThumber只需要更改影子图片的URL即可实现缩放，只需要图片的文件名末尾加入以逗号分隔的参数即可：

###根据宽度缩放:

'*w*'参数是Width的缩写，可以控制图片按宽度缩放。下面的URL会生成一张300px宽的图片：

    http://EvaThumber.avnpc.com/thumb/demo,w_300.jpg

![EvaThumber Resized Image](http://EvaThumber.avnpc.com/thumb/demo,w_300.jpg)

###根据高度缩放:

同理通过更改'*h*'（Height），根据高度缩放图片：

    http://EvaThumber.avnpc.com/thumb/demo,h_150.jpg

![EvaThumber Resized Image](http://EvaThumber.avnpc.com/thumb/demo,h_150.jpg)

###按百分比缩放:

当w或h为小数时，图片会按照百分比缩放，比如w_0.4会将图片缩放至原尺寸的40%：

    http://EvaThumber.avnpc.com/thumb/demo,w_0.4.jpg

![EvaThumber Resized Image](http://EvaThumber.avnpc.com/thumb/demo,w_0.4.jpg)

注意：

 - 当w与h既有整数又有小数时，以整数为准
 - 当w与h同时为小数时，以w为准

图片剪裁
----

使用'*c*'参数（Crop）可以剪裁图片，比如c_100会从图片的中心位置截取出一张100px的缩略图。

    http://EvaThumber.avnpc.com/thumb/demo,c_100.jpg

![EvaThumber Resized Image](http://EvaThumber.avnpc.com/thumb/demo,c_100.jpg)

'*g*'参数（gravity）代表剪裁范围或高度，需要配合c参数一起使用。比如下例，代表从图片中心位置剪裁一张100px*200px的缩略图。

    http://EvaThumber.avnpc.com/thumb/demo,c_200,g_100.jpg

![EvaThumber Resized Image](http://EvaThumber.avnpc.com/thumb/demo,c_200,g_100.jpg)

如果想要指定剪裁的精确位置，需要用'x'和'y'参数指定起点坐标，比如下面的例子，代表以距离图片左边80px，上边10px为起点，剪裁一张100px*200px的图片。

    http://EvaThumber.avnpc.com/thumb/demo,c_100,g_200,x_80,y_10.jpg

![EvaThumber Resized Image](http://EvaThumber.avnpc.com/thumb/demo,c_100,g_200,x_80,y_10.jpg)

图片的剪裁与缩放可以混用，EvaThumber始终会先进行剪裁，然后再对剪裁后的图片缩放。

    http://EvaThumber.avnpc.com/thumb/demo,c_100,g_200,w_50.jpg

![EvaThumber Resized Image](http://EvaThumber.avnpc.com/thumb/demo,c_100,g_200,w_50.jpg)

###填充模式

在实际使用中，我们经常会遇到这样的场景：需要截取并缩放图片以适应网页布局，此时我们可以使用剪裁中的填充模式，在填充模式下，需要指定剪裁参数为c_fill，同时设定填充的宽度与高度，然后可以得到一张完全吻合设定尺寸，同时经过缩放与剪裁处理的图片。

    http://EvaThumber.avnpc.com/thumb/demo,c_fill,w_250,h_50.jpg

![EvaThumber Resized Image](http://EvaThumber.avnpc.com/thumb/demo,c_fill,w_250,h_50.jpg)

在填充模式下还可以设定剪裁范围，允许的剪裁范围包括'top'（从上方）, 'bottom'（从下方）, 'left'（从左）， 'right'（从右）。

    http://EvaThumber.avnpc.com/thumb/demo,c_fill,g_top,w_250,h_60.jpg

![EvaThumber Resized Image](http://EvaThumber.avnpc.com/thumb/demo,c_fill,g_top,w_250,h_60.jpg)

旋转
-----------------

旋转参数为'*r*' (rotate) ，传递一个数字作为图片旋转的角度，比如让图片按照逆时针旋转90度：

    http://EvaThumber.avnpc.com/thumb/demo,h_200,r_90.jpg

![EvaThumber Resized Image](http://EvaThumber.avnpc.com/thumb/demo,h_200,r_90.jpg)

图片滤镜
-------------

图片边线
------------

图片水印
------------



图片压缩质量
------------

通过'*q*'(quality)可以指定jpg图片的压缩质量，默认为100:

    http://EvaThumber.avnpc.com/thumb/demo,h_200,q_10.jpg

![EvaThumber Resized Image](http://EvaThumber.avnpc.com/thumb/demo,h_200,q_10.jpg)


魔术功能
============


自动获得随机高质量图片素材
---------------

面部识别
----------------

图片优化
--------------

二维码
--------------


安全问题
===========

URL唯一化
----------

设置允许尺寸
------------

只允许子域名访问静态缓存
----------




安装与设置
========

下载
------------


下载 [最新版本的EvaThumber](https://github.com/AlloVince/EvaThumber/zipball/master)，解压即可使用。

安装
------------

###1. 环境需求

1. PHP 版本大于 5.3.0
2. 以安装GD 2.0+
3. 服务器已经开启Url Re-write模块


###2. 开启静态缓存

####Apache设置：

如果服务器为Apache并且已经开启[mod_rewrite](http://httpd.apache.org/docs/current/mod/mod_rewrite.html)模块，则无需任何设置，重写规则已经写入.htaccess文件。

####Nginx

请参考以下配置调整路径

    server {
            listen   80;
            server_name  EvaThumber.avnpc.com;
            location / {
                    root  /usr/www/EvaThumber/;
                    index index.php index.html index.htm;
                    if (!-e $request_filename){
                       rewrite ^/(.*)$ /index.php?$1& last;
                    }
            }
            location ~ \.php$ {
                    include fastcgi_params;
                    fastcgi_pass   127.0.0.1:9000;
                    fastcgi_index  index.php;
                    fastcgi_param  SCRIPT_FILENAME  /usr/www/EvaThumber/$fastcgi_script_name;
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


复写配置文件
------------


其他
=======

寻求帮助
----

贡献代码
----

相关技术
----

EvaThumber 使用了以下的开源技术：

 - [PHP Thumb](https://github.com/masterexploder/PHPThumb) : 好用的缩略图生成库;
 - [Cloudinary](http://cloudinary.com/) : API设计参考了著名云服务Cloudinary;

许可证
-------

EvaThumber 是 [EvaEngine](https://github.com/AlloVince/eva-engine)项目的一个前端组件，基于[New BSD License](http://framework.zend.com/license/new-bsd)发布，简单说，你可以将EvaThumber用与任何商业或非商业项目中，可以自由更改EvaThumber的源代码，惟一做的是保留源代码中的作者信息。

捐赠
-------

感谢
---------
实例图片来自 [Рыбачка](http://nzakonova.35photo.ru/photo_391467/)


旧版本
-------

EvaThumber由[EvaImageCloud](http://avnpc.com/pages/evacloudimage)更名而来，基本兼容旧版的API并作了完全的重构。旧版本代码[在此](https://github.com/AlloVince/EvaCloudImage/tree/42941a86af2b5fe92a5a3376010cfad607cce555)
