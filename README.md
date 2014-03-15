[EvaThumber](http://avnpc.com/pages/evathumber) 是一个基于URL的轻量级图片处理库，支持缩放/旋转/截取/滤镜等多种常用图片处理，支持设置水印/二维码，并且可以进行面部识别以及PNG优化压缩，所有处理仅仅需要通过更改图片的URL。

EvaThumber基于PHP实现，可以一键安装在任何主流系统，由于基于URL实现接口，所以其他编程语言也可以使用EvaThumber作为前端组件。

EvaThumber is a url based image transformation php library. See [English version document](https://github.com/AlloVince/EvaThumber/blob/master/README.md).

EvaThumber的源代码存放于[Github](https://github.com/AlloVince/EvaThumber)，完全开源，欢迎[Fork](https://github.com/AlloVince/EvaThumber)或[关注我](https://github.com/AlloVince)。

下面是几个实例：

原图：

    http://evathumber.avnpc.com/upload/demo.jpg

![EvaThumber Image Demo](http://evathumber.avnpc.com/upload/demo.jpg)

EvaThumber的处理：裁剪为宽100，高100，加黑白滤镜，输出为png格式

    http://evathumber.avnpc.com/thumb/d/demo,c_fill,f_gray,h_100,w_100.png

![EvaThumber Image Demo](http://evathumber.avnpc.com/thumb/d/demo,c_fill,f_gray,h_100,w_100.png)

按宽度缩小到150px，旋转180度，加上水印，压缩质量为60%。

    http://evathumber.avnpc.com/thumb/watermark/demo,q_60,r_180,w_150.jpg

![EvaThumber Image Demo](http://evathumber.avnpc.com/thumb/watermark/demo,q_60,r_180,w_150.jpg)

使用二维码作为水印，水印放在图片中央，缩小到原图的50%

    http://evathumber.avnpc.com/thumb/watermark2/demo,p_50.jpg

![EvaThumber Image Demo](http://evathumber.avnpc.com/thumb/watermark2/demo,p_50.jpg)

在项目初期原型设计时，想要做一个图片墙功能，一般需要一些高质量的图片作为素材先看看效果，还在为一张一张找然后一张一张裁剪而感到厌烦？

EvaThumber只需要直接书写HTML，高素质的图片会自动下载并剪裁为指定尺寸展示出来：

    <div style="max-width:800px;" class="thumbnails">
	<div class="span4"><a class="thumbnail" href="#"><img src="http://evathumber.avnpc.com/thumb/d/01,c_fill,d_flickr,h_270,w_360.jpg"></a></div>
	<div class="span3"><a class="thumbnail" href="#"><img src="http://evathumber.avnpc.com/thumb/d/02,c_fill,d_picasa,h_128,w_260.jpg"></a></div>
	<div class="span2"><a class="thumbnail" href="#"><img src="http://evathumber.avnpc.com/thumb/d/03,c_fill,d_picasa,h_128,w_160.jpg"></a></div>
	<div class="span3"><a class="thumbnail" href="#"><img src="http://evathumber.avnpc.com/thumb/d/04,c_fill,d_picasa,h_128,w_260.jpg"></a></div>
	<div class="span2"><a class="thumbnail" href="#"><img src="http://evathumber.avnpc.com/thumb/d/05,c_fill,d_picasa,h_128,w_160.jpg"></a></div>
	</div>

<div style="max-width:800px;" class="thumbnails">
<div class="span4"><a class="thumbnail" href="#"><img src="http://evathumber.avnpc.com/thumb/d/01,c_fill,d_flickr,h_270,w_360.jpg" alt=""></a></div>
<div class="span3"><a class="thumbnail" href="#"><img src="http://evathumber.avnpc.com/thumb/d/02,c_fill,d_picasa,h_128,w_260.jpg" alt=""></a></div>
<div class="span2"><a class="thumbnail" href="#"><img src="http://evathumber.avnpc.com/thumb/d/03,c_fill,d_picasa,h_128,w_160.jpg" alt=""></a></div>
<div class="span3"><a class="thumbnail" href="#"><img src="http://evathumber.avnpc.com/thumb/d/04,c_fill,d_picasa,h_128,w_260.jpg" alt=""></a></div>
<div class="span2"><a class="thumbnail" href="#"><img src="http://evathumber.avnpc.com/thumb/d/05,c_fill,d_picasa,h_128,w_160.jpg" alt=""></a></div>
</div>


为什么用EvaThumber
===================

- 一切基于URL，人人可用，任何项目均可集成
- 所见即所得，前端人员无痛调试
- 设计人员在项目前期大量图片素材自动获取
- 同时支持GD/Imagick/Gmagick三大主流图片处理库，几乎可在所有服务器上部署
- 面部识别/水印/PNG优化压缩等更多有趣功能


基本功能 (URL API)
========

URL基本构成
-----------

一个典型的EvaThumber的URL形如：

http://evathumber.avnpc.com/<span class="label label-info">thumb</span>/<span class="label label-success">default</span>/<span class="label">abc/demo</span><span class="label label-important">,c_fill,w_100,h_100</span><span class="label label-inverse">.gif</span>

高亮的部分分别是：

- <span class="label label-info">前缀 prefix</span> 可在配置文件中设置，一般是缓存存放的文件夹名
- <span class="label label-success">配置文件名 configKey</span> 因为一个EvaThumber可以对应多组配置文件，这里用来区分当前正在使用哪一组配置。
- <span class="label">图片路径</span> 根据图片路径才能找到源文件，如果是压缩包内文件也需要完整的路径
- <span class="label label-important">操作参数</span> 多个参数以逗号分隔，参数内以下划线区别参数名和值
- <span class="label label-inverse">输出格式</span> 更改文件扩展名即可更改图片输出格式

举例说明，我们的配置文件为：

    'thumbers' => array(
        'default' => array(
            'source_path' => '/usr/www/upload',
            'prefix' => 'thumb',
        ),
        'another' => array(
            'source_path' => '/usr/www/another',
        ),
    ),

此时访问

    http://evathumber.avnpc.com/thumb/default/abc/demo,c_fill,w_100,h_100.gif

首先会找到配置文件的`default`片段，然后在`/usr/www/upload/abc`下查找文件名为`demo`的图片文件。

同理

    http://evathumber.avnpc.com/thumb/another/foo.png

会搜索`/usr/www/another`下的`foo.*`文件

影子模式
--------------

很多时候我们不希望暴露原图片的地址，此时可以通过EvaThumber自动生成原图片的影子图片，保护原图片URL不被泄露，比如

 - 原图片地址为 : [http://evathumber.avnpc.com/upload/demo.jpg](http://evathumber.avnpc.com/upload/demo.jpg)
 - 影子图片地址为 : [http://evathumber.avnpc.com/thumb/d/demo.jpg](http://evathumber.avnpc.com/thumb/d/demo.jpg)，在网站中只需要公布影子图片即可

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

    http://evathumber.avnpc.com/thumb/max/demo.jpg

![EvaThumber Image Demo](http://evathumber.avnpc.com/thumb/max/demo.jpg)

图片格式转换
-------------

EvaThumber支持的图片格式有：

- [GIF (Graphics Interchange Format)](http://en.wikipedia.org/wiki/Graphics_Interchange_Format)
- [JPEG](http://en.wikipedia.org/wiki/JPEG)
- [PNG (Portable Network Graphics)](http://en.wikipedia.org/wiki/Portable_Network_Graphics)
- [WBMP (Wireless Application Protocol Bitmap Format)](http://en.wikipedia.org/wiki/Wireless_Application_Protocol_Bitmap_Format)
- [XBM (X BitMap)](http://en.wikipedia.org/wiki/X_BitMap)

支持在任意两种格式间转换，只需要更改URL最后的扩展名即可，比如

    http://evathumber.avnpc.com/thumb/d/demo,w_100.gif
    http://evathumber.avnpc.com/thumb/d/demo,w_100.xbm

![EvaThumber Image Demo](http://evathumber.avnpc.com/thumb/d/demo,w_100.gif)

图片缩放
-----------------


###根据宽度缩放 `w_[int Width]`:

`w_`允许输入一个整数，控制图片按宽度缩放，下面的URL会生成一张100px宽的图片：

    http://evathumber.avnpc.com/thumb/d/demo,w_100.jpg

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/d/demo,w_100.jpg)

###根据高度缩放 `h_[int Height]`:

同理`h_`允许输入一个整数，控制图片按高度缩放，下面的URL会生成一张50px高的图片：

    http://evathumber.avnpc.com/thumb/d/demo,h_50.jpg

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/d/demo,h_50.jpg)

###按百分比缩放 `p_[int Percent]`:

`p_`允许输入一个1-100的数字，图片会按照百分比缩放，比如p_30会将图片缩放至原尺寸的30%：

    http://evathumber.avnpc.com/thumb/d/demo,p_30.jpg

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/d/demo,p_30.jpg)

###允许拉伸：

在缩放图片时，如果缩放尺寸大于图片本身的尺寸，操作默认会被忽略，但是也可以在配置文件中强制开启

    'thumbers' => array(
        'stretch' => array(
            'allow_stretch' => true,
        ),
    ）,

此时图片允许被强制拉伸。

    http://evathumber.avnpc.com/thumb/stretch/demo,w_310.jpg


图片剪裁
----

###基本正方形剪裁 `c_[int Crop]`:

`c_` 允许输入一个整数，如`c_50`会从图片的中心位置截取出一张50px*50px的缩略图。

    http://evathumber.avnpc.com/thumb/d/demo,c_50.jpg

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/d/demo,c_50.jpg)

###指定尺寸的剪裁 `c_[int Crop]` + `g_[int Gracity]`:

配合`c_`输入`g_`，代表指定剪裁的宽度与高度，比如`c_50,g_30`，代表从图片中心位置剪裁一张50px*30px的缩略图。

    http://evathumber.avnpc.com/thumb/d/demo,c_50,g_30.jpg

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/d/demo,c_50,g_30.jpg)

###指定坐标 `x_[int X]` + `y_[int y]`:

如果想要指定剪裁的精确位置，需要用`x_`和`y_`参数指定起点坐标，`x_0,y_0` 以图片左上角为坐标原点。

比如 `c_50,g_60,x_40,y_30` 代表以距离图片左边40px，上边30px为起点，剪裁一张50px*60px的图片。

    http://EvaThumber.avnpc.com/thumb/demo,c_100,g_200,x_80,y_10.jpg

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/d/demo,c_50,g_60,x_40,y_30.jpg)

图片的剪裁与缩放可以混用，EvaThumber始终会先进行剪裁，然后再对剪裁后的图片缩放。

    http://evathumber.avnpc.com/thumb/d/demo,c_50,g_60,x_40,y_30,w_30.jpg

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/d/demo,c_50,g_60,x_40,y_30,w_30.jpg)

###填充模式 `c_fill` + `w_[int Width]` + `h_[int Height]`

在实际使用中，我们经常会遇到这样的场景：需要截取并缩放图片以适应网页布局，此时我们可以使用剪裁中的填充模式，在填充模式下，需要指定剪裁参数为`c_fill`，同时设定填充的宽度与高度，然后可以得到一张完全吻合设定尺寸，同时经过缩放与剪裁处理的图片。

    http://evathumber.avnpc.com/thumb/d/demo,c_fill,w_50,h_50.jpg

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/d/demo,c_fill,w_50,h_50.jpg)

<!--
在填充模式下还可以用`g_`设定剪裁范围，允许的剪裁范围包括`g_top`（从上方）, `g_bottom`（从下方）, `g_left`（从左）， `g_right`（从右）。

    http://evathumber.avnpc.com/thumb/d/demo,c_fill,g_left,h_50,w_50.jpg

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/d/demo,c_fill,g_left,h_50,w_50.jpg)
-->

图片旋转 `r_[int Rotate]`
-----------------

`r_` 允许指定一个1-360的数字作为图片旋转的角度，比如`r_90`让图片按照*顺时针*旋转90度：

    http://evathumber.avnpc.com/thumb/d/demo,r_90,w_50.png

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/d/demo,r_90,w_50.png)

图片滤镜 `f_[string Filter]`
-------------

目前支持的滤镜有：

- `f_gray` 黑白滤镜

图片压缩质量  `q_[int Quality]`
------------

`q_` 允许指定一个1-100的参数设置jpg图片的压缩质量:

    http://evathumber.avnpc.com/thumb/d/demo,w_100,q_10.jpg

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/d/demo,w_100,q_10.jpg)

也可以在配置文件中设置一个全局压缩质量：

    'thumbers' => array(
        'd' => array(
            'quality' => 70,
        ),
    ）,

图片水印
------------

###图片水印

可以在配置文件中指定`layer_file`为一张图片作为水印：

    'thumbers' => array(
        'd' => array(
            'watermark' => array(
                'enable' => 1,
                'position' => 'br',
                'layer_file' => __DIR__ . '/layers/watermark.png',
            ),
        ),
    ）,

水印的位置可以在：

- `tl` : Top Left 左上
- `tr` : Top Right 右上
- `bl` : Bottom Left 左下
- `br` : Bottom Right 右下
- `center` : 中央

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/watermark/demo,q_70,w_150.jpg)

###文字水印

同样可以直接使用文字作为水印

    'thumbers' => array(
        'd' => array(
            'watermark' => array(
                'enable' => 1,
                'position' => 'br',
                'layer_file' => '',
                'text' => 'EvaThumber',
                'font_file' => __DIR__ . '/layers/Yahei_Mono.ttf',
                'font_size' => 12,
                'font_color' => '#FFFFFF',
            ),
        ),
    ）,

此时必须将`layer_file`指定为空，同时设置：

- `font_file` : 水印使用的ttf字体文件
- `font_size` : 字体大小
- `font_color` : 字体颜色
- `text` : 文字内容

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/watermark3/demo,w_150.jpg)

如果文字中包含中文等字符，需要ttf字体包含该字符集，否则可能出现乱码。

水印会在最后添加上去，不会受图片缩放旋转的影响。

扩展功能
============

二维码水印
-----------

作为扩展功能，二维码水印暂时只采用GD库生成，需要配置以下项目：


    'thumbers' => array(
        'd' => array(
            'watermark' => array(
                'enable' => 1,
                'position' => 'center',
                'layer_file' => '',
                'text' => 'http://avnpc.com/pages/evathumber',
                'qr_code' => 1,
                'qr_code_size' => 2,
                'qr_code_margin' => 1,
            ),
        ),
    ),

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/watermark2/demo,p_50.jpg)

- `qr_code` 是否启用二维码 1/0
- `qr_code_size` 二维码大小
- `qr_code_margin` 二维码内边距



自动素材
-----------

通过设置`d_dummy`可以自动获得优质的图片素材，EvaThumber内置了两个默认图片源：

- `d_picasa`， 从[Picasa](https://picasaweb.google.com/lh/explore)获得图片
- `d_flickr`，从[Flickr](http://www.flickr.com/explore)获得图片

当自动素材功能启用时，<span class="label">图片路径</span>可随意填写：

    http://evathumber.avnpc.com/thumb/d/foo,c_fill,d_picasa,h_100,w_100.jpg
    http://evathumber.avnpc.com/thumb/d/bar,c_fill,d_flickr,h_100,w_100.jpg

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/d/foo,c_fill,d_picasa,h_100,w_100.jpg)
![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/d/bar,c_fill,d_flickr,h_100,w_100.jpg)

读取压缩包
-----------

如果在配置文件中指定`source_path`为一个ZIP压缩包文件，则可以直接从压缩包中读取图片信息无需解压。比如

    'thumbers' => array(
        'zip' => array(
            'source_path' => __DIR__ . '/upload/archive.zip',
            'zip_file_encoding' => 'GB2312',
        ),
    ）,

如果压缩包中路径或文件名含有非英语字符，则需要指定压缩包压缩时的系统编码，一般来说中文系统需要指定为`GB2312`。

如压缩包内文件结构为

    - archive.zip
        - archive/
            zipimage.jpg
            中文.jpg

访问

    http://evathumber.avnpc.com/thumb/zip/archive/zipimage,w_100.jpg

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/zip/archive/zipimage,w_100.jpg)

    http://evathumber.avnpc.com/thumb/zip/archive/中文,w_100.jpg

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/zip/archive/%E4%B8%AD%E6%96%87,w_100.jpg)

面部识别
----------------

剪裁图片时默认的方式是通过指定`x_`和`y_`坐标来选择剪裁的区域，不过如果是带有人物的图片，可以试试使用`c_face`去自动识别人物面部坐标，比如下图

    http://evathumber.avnpc.com/thumb/d/face,w_100.jpg

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/d/face,w_100.jpg)

使用默认的剪裁方式：

    http://evathumber.avnpc.com/thumb/d/face,c_100.jpg

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/d/face,c_100.jpg)

使用面部识别：

    http://evathumber.avnpc.com/thumb/d/face,c_face,w_100,h_100.jpg

![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/d/face,c_face,w_100,h_100.jpg)


PNG图片优化
--------------

EvaThumber可以对PNG图片进行无损优化，优化基于[PNGOut](http://advsys.net/ken/utils.htm)项目，下载pngout.exe到bin文件夹，配置以下项目：

    'thumbers' => array(
        'png' => array(
            'png_optimize' => array(
                'enable' => 1,
                'pngout' => array(
                    'bin' => __DIR__ . '/bin/pngout.exe',
                ),
            ),
        ),
    ）,

对比一下有无图片优化的效果：

- ![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/d/demo,w_150.png) 58.8K
- ![EvaThumber Resized Image](http://evathumber.avnpc.com/thumb/png/demo,w_150.png) 26.5K


安全问题
===========

图片的动态生成，在实际部署上可能会带来两方面的问题：

1. 如果开启缓存，那么每一张图片可能会有无数种排列组合，如果被恶意请求，很可能短时间内产生大量文件把磁盘塞满。
2. 图片处理比较吃内存和CPU，在高并发环境下，服务器性能可能无法跟上。

对于问题1，EvaThumber采用如下的方法应对：

URL唯一化
----------

EvaThumber对于同样操作的请求，以及带有多余参数的请求，都会被重定向到唯一的一个URL地址，比如

- `http://evathumber.avnpc.com/thumb/d/demo,w_1000.jpg` 宽度超出
- `http://evathumber.avnpc.com/thumb/d/demo,x_10.jpg` 不必要参数
- `http://evathumber.avnpc.com/thumb/d/demo,,,,.jpg` 错误参数

等等情况，都会被重定向到唯一的URL`http://evathumber.avnpc.com/thumb/d/demo.jpg`。从而保证缓存尽可能少的产生。

设置允许尺寸
------------

在大部分Web应用中，其实图片都会被缩放为固定的几个尺寸，所以可以在配置文件中指定允许被缩放的尺寸：

    'thumbers' => array(
        'd' => array(
            'allow_sizes' => array(
                '200*100',
            ),
        ),
    ）,

此时图片只允许被缩放为`w_200,h_100`

设置禁止操作
------------

EvaThumber所提供的功能并不一定能全部用到，可以在配置文件中禁用某些操作

    'thumbers' => array(
        'd' => array(
            'disable_operates' => array(
                'filter',
                'rotate',
                'dummy',
            ),
        ),
    ）,

可以禁用的操作包括：

- 'crop' 剪裁
- 'dummy' 自动素材
- 'filter' 滤镜
- 'gravity' 剪裁范围
- 'height' 按高度缩放
- 'percent' 按百分比缩放
- 'quality' 压缩质量
- 'rotate' 旋转
- 'width' 按宽度缩放
- 'x' 选取剪裁位置
- 'y' 选取剪裁位置
- 'extension' 更改输出格式

在正式环境中，可以同时设置允许尺寸以及禁用操作。典型的场景如用户上传头像，配置文件设置为：

    'thumbers' => array(
        'd' => array(
            'max_width' => 300,
            'max_height' => 300,
            'allow_sizes' => array(
                '200*200',
                '100*100',
                '50*50',
            ),
            'disable_operates' => array(
                'dummy',
                'filter',
                'gravity',
                'percent',
                'quality',
                'rotate',
                'x',
                'extension',
            ),
        ),
    ),

此时一张图片最多产生原图+3个尺寸的缩略图，已经不需要特别做安全设置了。

高并发环境下的部署建议
----------

在高并发环境下，不建议直接暴露EvaThumber给最终用户。推荐的方式是配合一个消息队列，在后台运行EvaThumber生成项目所需要的所有规格缩略图，来看一个实际的例子：

1. 假设EvaThumber部署在`/usr/www/EvaThumber`
2. 绑定一个内网域名evathumber.local到`/usr/www/EvaThumber`，此域名只有服务器之间能访问，外网无法访问
3. 将用户上传图片路径指定为`/usr/www/EvaThumber/upload`
4. 用户上传图片demo.jpg到`/usr/www/EvaThumber/upload/demo.jpg`
5. 开启EvaThumber缓存，缓存路径指定为`/usr/www/EvaThumber/thumb`，同时向消息队列发送消息，消息的内容都是EvaThumber URL，如`http://evathumber.local/thumb/d/demo,w_100.jpg`
6. 运行消息队列，因为每一条消息都是一个URL，可以用cURL直接访问。此时会生成缓存`/usr/www/EvaThumber/thumb/demo,w_100.jpg`
7. 绑定外网可以访问的域名如evathumber.avnpc.com到`/usr/www/EvaThumber/thumb`
8. 用户可以通过`http://evathumber.avnpc.com/demo,w_100.jpg`访问到最终结果。



安装与设置
========

快速开始
---------

在Linux下，用4行命令完成安装，假设web服务器目录为`/opt/htdocs`。

    cd /opt/htdocs
    git clone git://github.com/AlloVince/EvaThumber.git
    cd EvaThumber
    composer install

然后访问`http://localhost/EvaThumber/index.php/thumb/d/demo.jpg` 即可看到示例的Demo图片并进行操作了。


安装基础功能
------------

基础功能包括：

- 图片缩放/剪裁/旋转等基础操作
- 图片滤镜
- 水印 （文字水印与图片水印）

如果已经有Composer，再EvaThumber目录下直接运行即可支持基础功能：

    composer install

如果没有安装Composer，参考下文：

###Windows下安装Composer

假设php.exe目录在d:\xampp\php，那么首先将php目录加入windows环境变量。

    cd d:\xampp\php
    php -r "eval('?>'.file_get_contents('https://getcomposer.org/installer'));"


同目录下编辑文件 composer.bat，内容为

    @ECHO OFF
    SET composerScript=composer.phar
    php "%~dp0%composerScript%" %*

运行

    composer -V
    
检查composer安装是否成功。

###Linux下安装Composer

以Ubuntu为例

~~~~
apt-get install curl
cd /usr/local/bin
curl -s http://getcomposer.org/installer | php
chmod a+x composer.phar
alias composer='/usr/local/bin/composer.phar'
~~~~

###开启URL Rewirte

开启URL Rewirte之后，可以省略URL中的`index.php`部分，如果已经生成缓存，则会优先显示缓存，所以在生产环境中是必须要打开的。

####Apache开启URL Rewirte

如果服务器为Apache并且已经开启[mod_rewrite](http://httpd.apache.org/docs/current/mod/mod_rewrite.html)模块，则无需任何设置，重写规则已经写入.htaccess文件。

####Nginx开启URL Rewirte

请参考以下配置调整路径

    server {
            listen   80;
            server_name  evathumber.avnpc.com;
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


###配置文件复写

因为配置文件可能经常需要修改，如果采用了GIT或SVN这样的版本控制工具非常容易引起冲突，所以这里并不推荐直接编辑`config.default.php`文件。

推荐的方法是新建一个`config.local.php`文件放于EvaThumber目录下，内容则与`config.default.php`保持同样的结构，所有的项目会自动复写默认设置。


###开启缓存

缓存启用依赖于URL Rewrite已经启用。对于每一组配置文件，都可以通过`'thumb_cache_path'`指定一个缓存放置的路径，同时将该组的`'cache'`设置为1。

以上文的Nginx配置为例

    'thumbers' => array(
        'd' => array(
			'thumb_cache_path' => '/usr/www/EvaThumber/thumb',
            'cache' => 1,
        ),
    ）,


缓存开启后，需要把URL中的`index.php/`部分去掉。

    http://evathumber.avnpc.com/index.php/thumb/d/demo,w_100.jpg

需要更改为：

    http://evathumber.avnpc.com/thumb/d/demo,w_100.jpg

用户第一次访问时，Nginx会将请求重写到`index.php`并生成缓存；当用户第二次访问时，会由Nginx优先命中`/usr/www/EvaThumber/thumb/d/demo,w_100.jpg`，不会访问到php。



安装扩展功能
---------

扩展功能包括：

- 二维码水印
- 随机素材
- 面部识别
- PNG压缩

除面部识别以外，只需要运行

    composer install --dev

如果已经安装了基础功能，运行

    composer update --dev

###面部识别功能安装

面部识别基于[OpenCV](http://opencv.org/)项目，可以参考官方网站的[OpenCV安装指南](http://docs.opencv.org/doc/tutorials/introduction/table_of_content_introduction/table_of_content_introduction.html)安装。

EvaThumber用Python实现了一个轻量Hook，在`bin/opencv.py`下，也可以在配置文件中指定路径

    'thumbers' => array(
        'd' => array(
            'face_detect' => array(
                'enable' => 0,
                'draw_border' => 1,
                'cascade' => '',
                'bin' => __DIR__ . '/bin/opencv.py',
            ),
        ),
    ）,

可配置的选项包括

- `'draw_border'` ： 是否绘制判定面部的边线
- `'cascade'` ： 这里指定一个OpenCV的[Haar Cascade](http://alereimondo.no-ip.org/OpenCV/34)XML文件的位置，OpenCV已经在源代码的`data`下提供了很多组，当然也可以自己做[特征训练](http://docs.opencv.org/trunk/doc/user_guide/ug_traincascade.html)制作特殊的Haar Cascade
- `'bin'` : OpenCV Python Hook的位置


###PNGOut安装

Windows下，直接下载[PNGOut.exe](http://advsys.net/ken/util/pngout.exe)放置于bin目录下。

Linux下载[PNGOUT的Linux版本](http://www.jonof.id.au/kenutils)，解压后在配置文件中配置pngout的路径即可。

    wget http://static.jonof.id.au/dl/kenutils/pngout-20130221-linux.tar.gz
    tar -xvf pngout-20130221-linux.tar.gz

解压后可以看到针对各种CPU的不同编译版本，一个简单判别的方法是进入各目录直接运行

   ./pngout -h
 
如果有输出则支持当前CPU



其他
=======

寻求帮助
----

如果有任何问题，请移步至[EvaThumber的Issues页面](https://github.com/AlloVince/EvaThumber/issues)提交BUG

贡献代码
----

也欢迎对EvaThumber发起Pull Request，你可以首先[Fork EvaThumber](https://github.com/AlloVince/EvaThumber/fork)。

相关技术
----

EvaThumber基于以下技术实现：

 - [Imagine](https://github.com/avalanche123/Imagine) : 统一接口的图片处理库。基础功能依赖
 - [Requests](https://github.com/rmccue/Requests) : 轻量级HTTP Client，无需cURL支持。扩张功能`自动素材`依赖
 - [PHPQRCode](https://github.com/aferrandini/PHPQRCode) : 生成QR码的PHP库。扩展功能`二维码水印`依赖
 - [PNGOUT](http://advsys.net/ken/utils.htm) ： PNG图片优化压缩工具
 - [Symfony Process Component](http://symfony.com/doc/current/components/process.html) : 轻巧的系统命令行调用
 - [OpenCV](http://opencv.org/) : 计算机视觉识别库，`面部识别`依赖
 - [Cloudinary](http://cloudinary.com/) : 参考了Cloudinary的API设计;

许可证
-------

EvaThumber 是 [EvaEngine](https://github.com/AlloVince/eva-engine)项目的一个前端组件，基于[New BSD License](http://framework.zend.com/license/new-bsd)发布，简单说，你可以将EvaThumber用与任何商业或非商业项目中，可以自由更改EvaThumber的源代码，惟一做的是保留源代码中的作者信息。


感谢
---------
实例图片来自 [Рыбачка](http://nzakonova.35photo.ru/photo_391467/)


旧版本
-------

EvaThumber由[EvaCloudImage](http://avnpc.com/pages/evacloudimage)更名而来，基本兼容旧版的API并作了完全的重构。旧版本代码[在此](https://github.com/AlloVince/EvaThumber/tree/42941a86af2b5fe92a5a3376010cfad607cce555)



[![Bitdeli Badge](https://d2weczhvl823v0.cloudfront.net/AlloVince/evathumber/trend.png)](https://bitdeli.com/free "Bitdeli Badge")

