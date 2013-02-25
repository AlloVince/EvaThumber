[EvaThumber](http://avnpc.com/pages/evathumber) 是一个基于URL的轻量级图片处理库，支持缩放/旋转/截取/滤镜等多种常用图片处理，支持设置水印/二维码，并且可以进行面部识别以及PNG优化压缩，所有处理仅仅需要通过更改图片的URL。

EvaThumber基于PHP实现，可以一键安装在任何主流系统，由于基于URL实现接口，所以其他编程语言也可以使用EvaThumber作为前端组件。

EvaThumber is a light-weight & opensource url based image transformation php library. See [English version document](https://github.com/AlloVince/EvaThumber).

EvaThumber的源代码存放于[Github](https://github.com/AlloVince/EvaThumber)，完全开源，欢迎[Fork](https://github.com/AlloVince/EvaThumber)或[关注我](https://github.com/AlloVince)。

下面是几个实例：

原图：

    http://www.zf2.local/upload/demo.jpg

![EvaThumber Image Demo](http://www.zf2.local/upload/demo.jpg)

EvaThumber的处理：裁剪为宽100，高100，加黑白滤镜，输出为png格式

    http://www.zf2.local/thumb/d/demo,c_fill,f_gray,h_100,w_100.png

![EvaThumber Image Demo](http://www.zf2.local/thumb/d/demo,c_fill,f_gray,h_100,w_100.png)

按宽度缩小到150px，旋转180度，加上水印，压缩质量为60%。

    http://www.zf2.local/thumb/watermark/demo,q_70,r_180,w_150.jpg

![EvaThumber Image Demo](http://www.zf2.local/thumb/watermark/demo,q_70,r_180,w_150.jpg)

使用二维码作为水印，水印放在图片中央，缩小到原图的50%

    http://www.zf2.local/thumb/watermark2/demo,p_50.jpg

![EvaThumber Image Demo](http://www.zf2.local/thumb/watermark2/demo,p_50.jpg)

前端人员想要设计一个图片墙功能，想用一些高质量的图片作为素材，还需要去一张一张找？EvaThumber只需要这样：

    <ul class="thumbnails">
        <li class="span4"><a href="#" class="thumbnail"><img src="http://www.zf2.local/thumb/d/01,c_fill,d_picasa,h_100,w_100.jpg"></a></li>
        <li class="span4"><a href="#" class="thumbnail"><img src="http://www.zf2.local/thumb/d/02,c_fill,d_picasa,h_100,w_100.jpg"></a></li>
        <li class="span4"><a href="#" class="thumbnail"><img src="http://www.zf2.local/thumb/d/03,c_fill,d_picasa,h_100,w_100.jpg"></a></li>
    </ul>

<ul class="thumbnails">
<li class="span4"><a href="#" class="thumbnail"><img src="http://www.zf2.local/thumb/d/01,c_fill,d_picasa,h_100,w_100.jpg"></a></li>
<li class="span4"><a href="#" class="thumbnail"><img src="http://www.zf2.local/thumb/d/02,c_fill,d_picasa,h_100,w_100.jpg"></a></li>
<li class="span4"><a href="#" class="thumbnail"><img src="http://www.zf2.local/thumb/d/03,c_fill,d_picasa,h_100,w_100.jpg"></a></li>
</ul>


为什么用EvaThumber
===================

- 一切基于URL，人人可用，任何项目均可集成
- 所见即所得，前端人员无痛调试
- 设计人员在项目前期大量图片素材自动获取
- 同时支持GD/Imagick/Gmagick三大主流图片处理库，几乎可在所有服务器上部署
- 面部识别/水印/PNG优化压缩等更多有趣功能


目录结构
========



基本功能 (URL API)
========

URL基本构成
-----------



影子模式
--------------

很多时候我们不希望暴露原图片的地址，此时可以通过EvaThumber自动生成原图片的影子图片，保护原图片URL不被泄露，比如

 - 原图片地址为 : [http://www.zf2.local/upload/demo.jpg](http://www.zf2.local/upload/demo.jpg)
 - 影子图片地址为 : [http://www.zf2.local/thumb/d/demo.jpg](http://www.zf2.local/thumb/d/demo.jpg)，在网站中只需要公布影子图片即可

假如原图片位于多级的树形目录下，影子图片也会保持与原图片同样的目录结构，从原切换到影子只需要更改域名或者根目录。

###图片尺寸限制

如果图片由用户上传，那么可能会遇到非常大的图片，此时可以在配置文件中设置最大尺寸限制。比如

    'thumbers' => array(
        'max' => array(
            'max_width' => 100,
            'max_height' => 100,
        ),
    ）,

访问当前配置下的图片，图片宽度已经被限制为100：

    http://www.zf2.local/thumb/max/demo.jpg

![EvaThumber Image Demo](http://www.zf2.local/thumb/max/demo.jpg)

图片格式转换
-------------

EvaThumber支持的图片格式有：

- [GIF (Graphics Interchange Format)](http://en.wikipedia.org/wiki/Graphics_Interchange_Format)
- [JPEG](http://en.wikipedia.org/wiki/JPEG)
- [PNG (Portable Network Graphics)](http://en.wikipedia.org/wiki/Portable_Network_Graphics)
- [WBMP (Wireless Application Protocol Bitmap Format)](http://en.wikipedia.org/wiki/Wireless_Application_Protocol_Bitmap_Format)
- [XBM (X BitMap)](http://en.wikipedia.org/wiki/X_BitMap)

支持在任意两种格式间转换，只需要更改URL最后的扩展名即可，比如

    http://www.zf2.local/thumb/d/demo,w_100.gif
    http://www.zf2.local/thumb/d/demo,w_100.xbm

![EvaThumber Image Demo](http://www.zf2.local/thumb/d/demo,w_100.gif)

图片缩放
-----------------

EvaThumber只需要更改影子图片的URL即可实现缩放，只需要图片的文件名末尾加入以逗号分隔的参数即可：

###根据宽度缩放 `w_[int Width]`:

`w_`允许输入一个数字，控制图片按宽度缩放，下面的URL会生成一张100px宽的图片：

    http://www.zf2.local/thumb/d/demo,w_100.jpg

![EvaThumber Resized Image](http://www.zf2.local/thumb/d/demo,w_100.jpg)

###根据高度缩放 `h_[int Height]`:

同理`h_`允许输入一个数字，控制图片按高度缩放，下面的URL会生成一张50px高的图片：

    http://www.zf2.local/thumb/d/demo,h_50.jpg

![EvaThumber Resized Image](http://www.zf2.local/thumb/d/demo,h_50.jpg)

###按百分比缩放 `p_[int Percent]`:

`p_`允许输入一个1-100的数字，图片会按照百分比缩放，比如p_30会将图片缩放至原尺寸的30%：

    http://www.zf2.local/thumb/d/demo,p_30.jpg

![EvaThumber Resized Image](http://www.zf2.local/thumb/d/demo,p_30.jpg)

###允许拉伸：

在缩放图片时，如果缩放尺寸大于图片本身的尺寸，操作默认会被忽略，但是也可以在配置文件中强制开启

    'thumbers' => array(
        'stretch' => array(
            'allow_stretch' => true,
        ),
    ）,

此时图片允许被强制拉伸。

    http://www.zf2.local/thumb/stretch/demo,w_310.jpg

###最大/最小尺寸限制：

###允许尺寸：

图片剪裁
----

###基本正方形剪裁 `c_[int Crop]`:

`c_` 允许输入一个数字，如`c_50`会从图片的中心位置截取出一张50px*50px的缩略图。

    http://www.zf2.local/thumb/d/demo,c_50.jpg

![EvaThumber Resized Image](http://www.zf2.local/thumb/d/demo,c_50.jpg)

###指定尺寸的剪裁 `c_[int Crop]` + `g_[int Gracity]`:

配合`c_`输入`g_`，代表指定剪裁的宽度与高度，比如`c_50,g_30`，代表从图片中心位置剪裁一张50px*30px的缩略图。

    http://www.zf2.local/thumb/d/demo,c_50,g_30.jpg

![EvaThumber Resized Image](http://www.zf2.local/thumb/d/demo,c_50,g_30.jpg)

###指定坐标 `x_[int X]` + `y_[int y]`:

如果想要指定剪裁的精确位置，需要用`x_`和`y_`参数指定起点坐标，`x_0,y_0` 以图片左上角为坐标原点。

比如 `c_50,g_60,x_40,y_30` 代表以距离图片左边40px，上边30px为起点，剪裁一张50px*60px的图片。

    http://EvaThumber.avnpc.com/thumb/demo,c_100,g_200,x_80,y_10.jpg

![EvaThumber Resized Image](http://www.zf2.local/thumb/d/demo,c_50,g_60,x_40,y_30.jpg)

图片的剪裁与缩放可以混用，EvaThumber始终会先进行剪裁，然后再对剪裁后的图片缩放。

    http://www.zf2.local/thumb/d/demo,c_50,g_60,x_40,y_30,w_30.jpg

![EvaThumber Resized Image](http://www.zf2.local/thumb/d/demo,c_50,g_60,x_40,y_30,w_30.jpg)

###填充模式 `c_fill` + `w_[int Width]` + `h_[int Height]`

在实际使用中，我们经常会遇到这样的场景：需要截取并缩放图片以适应网页布局，此时我们可以使用剪裁中的填充模式，在填充模式下，需要指定剪裁参数为`c_fill`，同时设定填充的宽度与高度，然后可以得到一张完全吻合设定尺寸，同时经过缩放与剪裁处理的图片。

    http://www.zf2.local/thumb/d/demo,c_fill,w_50,h_50.jpg

![EvaThumber Resized Image](http://www.zf2.local/thumb/d/demo,c_fill,w_50,h_50.jpg)

在填充模式下还可以用`g_`设定剪裁范围，允许的剪裁范围包括`g_top`（从上方）, `g_bottom`（从下方）, `g_left`（从左）， `g_right`（从右）。

    http://www.zf2.local/thumb/d/demo,c_fill,g_left,h_50,w_50.jpg

![EvaThumber Resized Image](http://www.zf2.local/thumb/d/demo,c_fill,g_left,h_50,w_50.jpg)

图片旋转 `r_[int Rotate]`
-----------------

`r_` 允许指定一个1-360的数字作为图片旋转的角度，比如`r_90`让图片按照*顺时针*旋转90度：

    http://www.zf2.local/thumb/d/demo,r_90,w_50.png

![EvaThumber Resized Image](http://www.zf2.local/thumb/d/demo,r_90,w_50.png)

图片滤镜 `f_[string Filter]`
-------------

目前支持的滤镜有：

- `f_gray` 黑白滤镜
- `f_gamma` 

图片边线
------------


图片压缩质量  `q_[int Quality]`
------------

`q_` 允许指定一个1-100的参数设置jpg图片的压缩质量:

    http://www.zf2.local/thumb/d/demo,w_100,q_10.jpg

![EvaThumber Resized Image](http://www.zf2.local/thumb/d/demo,w_100,q_10.jpg)

也可以在配置文件中设置一个全局压缩质量：

    'thumbers' => array(
        'd' => array(
            'quality' => 70,
        ),
    ）,

图片水印
------------

###图片水印

###文字水印

###二维码水印

魔术功能
============


自动获得随机高质量图片素材
---------------

读取压缩包
-----------

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

设置允许操作
------------

只允许子域名访问静态缓存
----------

出错处理
--------


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
                       rewrite ^/(.*)$ /index.php last;
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



复写配置文件
------------

大规模部署
===========

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

