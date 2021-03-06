<?php

namespace darknet;

use FFI;
use darknet\Exception\coreException;

/**
 * Description of core
 * @package php-darknet (yolo v2,v3,v4)
 * @author Shubham Chaudhary <ghost.jat@gmail.com>
 * @copyright (c) 2019,2020, Shubham Chaudhary
 */
class core {

    protected const LIBDIR = '/home/ghost/bin/c-lib/darknet/';
    protected const CFG_FASTEST_YOLO = __DIR__.'/cfg/yolo-fastest.cfg';
    protected const WEIGHT_FASTEST_YOLO = __DIR__.'/weight/yolo-fastest.weights';
    protected const CFG_FASTEST_YOLO_XL = __DIR__.'/cfg/yolo-fastest-xl.cfg';
    protected const WEIGHT_FASTEST_YOLO_XL = __DIR__.'/weight/yolo-fastest-xl.weights';
    protected const CFG_MOBILENET_V2_LITE = self::LIBDIR.'cfg/MobileNetV2-YOLOv3-Lite-coco.cfg';
    protected const WEIGHT_MOBILENET_V2_LITE = self::LIBDIR.'backup/MobileNetV2-YOLOv3-Lite-coco.weights';
    protected const CFG_MOBILENET_V2_VOC = self::LIBDIR.'cfg/MobileNetV2-yolo-Fastest-voc-v2.cfg';
    protected const WEIGHT_MOBILENET_V2_VOC = self::LIBDIR.'backup/MobileNetV2-YOLO-Fastest-voc-v2.weights';
    protected const CFG_VOC_TINY_2 = self::LIBDIR.'cfg/yolov2-tiny-voc.cfg';
    protected const WEIGHT_VOC_TINY_2 = self::LIBDIR.'backup/yolov2-tiny-voc.weights';
    protected const CFG_TINY_2 = self::LIBDIR.'cfg/yolov2-tiny.cfg';
    protected const WEIGHT_TINY_2 = self::LIBDIR . 'backup/yolov2-tiny.weights';
    protected const CFG_TINY_3 = self::LIBDIR . 'cfg/yolov3-tiny.cfg';
    protected const WEIGHT_TINY_3 = self::LIBDIR . 'backup/yolov3-tiny.weights';
    protected const CFG_TINY_4 = self::LIBDIR . 'cfg/yolov4-tiny.cfg';
    protected const WEIGHT_TINY_4 = self::LIBDIR . 'backup/yolov4-tiny.weights';
    protected const CFG_MAIN = self::LIBDIR . 'cfg/yolov3.cfg';
    protected const WEIGHT_MAIN = self::LIBDIR . 'backup/yolov3.weights';
    public const DATA_COCO = self::LIBDIR . 'cfg/coco.data';
    public const DATA_IMGNET1k = self::LIBDIR . 'cfg/imagenet1k.data';
    public const DATA_OPNIMG = self::LIBDIR . 'cfg/openimages.data';
    public const DATA_COBIN9k = self::LIBDIR . 'cfg/combine9k.data';
    public const DATA_VOC = self::LIBDIR . 'cfg/voc.data';
    protected const OUTFILE_DIR = __DIR__ . '/../temp/out/';
    public const YOLO4TINY = 4;
    public const YOLO3TINY = 3;
    public const YOLO3MAIN = 2;
    public const YOLO2TINY = 1;
    public const YOLOFASTEST = 7;
    public const YOLOFASTEST_XL = 8;
    public const YOLO_VOC_TINY = 5;
    public const MOBILE_NET_V2_LITE_YOLO = 6;
    public const MOBILE_NET_V2_VOC = 9;

    protected $image, $itme, $net, $outPut, $pathInfo, $rawImage, $temp_name, $dets, $imgInfo;
    public $darknetFFI, $meta, $classes, $numBox;

    public function __construct($netType = self::YOLO4TINY, $metaData = self::DATA_COCO) {
        $this->numBox = FFI::new('int32_t');
        $this->darknetFFI = FFI::load(__DIR__ . '/dn_api_4.h');
        $this->getMeta($metaData);
        $this->classes();
        $this->loadNetwork($netType);
    }

    public function classify($img) {
        $this->loadImage($img);
        $out = $this->predictImg();
        $res = [];
        for ($i = 0; $i < $this->meta->classes; ++$i) {
            $res[$i] = (FFI::string($this->meta->names[$i], $out[$i]));
        }
        return $res;
    }

    public function detect($img, $remote = false, $thresh = .25, $hier_thresh = .5, $nms = .45) {
        $time = microtime(true);
        if ($remote) {
            $img = $this->img4base64($img);
            $this->loadImage($img);
        } else {
            $this->loadImage($img);
        }

        $this->imgInfo($img);
        $this->predictImg();
        $this->getNetworkBoxes($this->net, $this->image->w, $this->image->h, $thresh, $hier_thresh, null, 0, $this->numBox);
        $this->doNmsSort($this->dets, $this->numBox, $this->meta, $nms);

        for ($obj = 0; $obj < $this->numBox->cdata; ++$obj) {
            foreach ($this->classes as $key => $val) {
                if ($this->dets[$obj]->prob[$key]) {
                    $res[] = $this->detectObject($this->dets[$obj], $key, $val);
                }
            }
        }
        if ($remote) {
            $msg = $this->drawBbox($res);
            echo PHP_EOL;
            echo "FPS:{$this->fps($time)}".PHP_EOL;
            echo "Execution time:" . $this->consumedTime($time) . PHP_EOL;
            unlink($img);
            $this->freeImage($this->image);
            $this->freeDetections($this->dets, $this->numBox);
            unset($res);
            return $msg;
        } else {
            $this->drawBbox($res, self::OUTFILE_DIR . $this->imgInfo->name);
            unset($res);
        }
        echo PHP_EOL;
        echo "FPS:{$this->fps($time)}".PHP_EOL;
        echo "Execution time:" . $this->consumedTime($time) . PHP_EOL;
        $this->freeImage($this->image);
        $this->freeDetections($this->dets, $this->numBox);
        unset($time);
    }

    public function remotePR($client, $frame, $server = '') {
        if ($server !== '') {
            $msg = $this->detect($frame->data, true);
            $data = ['id' => $frame->fd, 'msg' => $msg];
            $server->push($client, json_encode($data));
        } else {
            $msg = $this->detect($frame, true);
            $data = ['id' => $client->resourceId, 'msg' => $msg];
            $client->send(json_encode($data));
        }
        unset($msg);
        unset($data);
    }

    protected function loadNetwork($yolov) {
        switch ($yolov) {
            case self::YOLO4TINY:
                $this->net = $this->darknetFFI->load_network(self::CFG_TINY_4, self::WEIGHT_TINY_4, 0);
                break;
            case self::YOLO3TINY:
                $this->net = $this->darknetFFI->load_network(self::CFG_TINY_3, self::WEIGHT_TINY_3, 0);
                break;
            case self::YOLO2TINY;
                $this->net = $this->darknetFFI->load_network(self::CFG_TINY_2, self::WEIGHT_TINY_2, 0);
                break;
            case self::YOLO_VOC_TINY;
                $this->net = $this->darknetFFI->load_network(self::CFG_VOC_TINY_2, self::WEIGHT_VOC_TINY_2, 0);
                break;
            case self::MOBILE_NET_V2_LITE_YOLO;
                $this->net = $this->darknetFFI->load_network(self::CFG_MOBILENET_V2_LITE, self::WEIGHT_MOBILENET_V2_LITE, 0);
                break;
            case self::MOBILE_NET_V2_VOC;
                $this->net = $this->darknetFFI->load_network(self::CFG_MOBILENET_V2_VOC, self::WEIGHT_MOBILENET_V2_VOC, 0);
                break;
            case self::YOLOFASTEST;
                $this->net = $this->darknetFFI->load_network(self::CFG_FASTEST_YOLO, self::WEIGHT_FASTEST_YOLO, 0);
                break;
            case self::YOLOFASTEST_XL;
                $this->net = $this->darknetFFI->load_network(self::CFG_FASTEST_YOLO_XL, self::WEIGHT_FASTEST_YOLO_XL, 0);
                break;
            case self::YOLO3MAIN;
                $this->net = $this->darknetFFI->load_network(self::CFG_MAIN, self::WEIGHT_MAIN, 0);
                break;
            default:
                throw new coreException("Not a valid version of yolo!\n");
                break;
        }
    }

    protected function loadImage($imgfile) {
        return $this->image = $this->darknetFFI->load_image_color($imgfile, 0, 0);
    }

    /**
     * [make_image description]
     * @param  int    $w [description]
     * @param  int    $h [description]
     * @param  int    $c [description]
     * @return [type]    [description]
     */
    public function makeImg(int $w, int $h, int $c) {
        return $this->darknetFFI->make_image($w, $h, $c);
    }

    /**
     * [resize_image description]
     * @param  FFI\CData $im [image]
     * @return [type]        [description]
     */
    public function resizeImg(FFI\CData $im) {
        return $this->darknetFFI->resize_image($im, $this->net->w, $this->net->h);
    }

    /**
     * [letterbox_image description]
     * @param  FFI\CData $im [description]
     * @param  int       $w  [description]
     * @param  int       $h  [description]
     * @return [type]        [description]
     */
    protected function letterBoxImg(FFI\CData $im, int $w, int $h) {
        return $this->darknetFFI->letterbox_image($im, $w, $h);
    }

    protected function predictImg() {
        return $this->darknetFFI->network_predict_image($this->net, $this->image);
    }

    protected function getMeta($data) {
        return $this->meta = $this->darknetFFI->get_metadata($data);
    }

    protected function classes() {
        for ($i = 0; $i < $this->meta->classes; ++$i) {
            $this->classes[$i] = \FFI::string($this->meta->names[$i]);
        }
        return $this->classes;
    }

    protected function getNetworkBoxes(FFI\CData $net, int $w, int $h, float $thresh, float $hier, $map, int $relative, $num) {
        return $this->dets = $this->darknetFFI->get_network_boxes($net, $w, $h, $thresh, $hier, $map, $relative, FFI::addr($num), 0);
    }

    protected function doNmsSort(FFI\CData $dects, FFI\CData $nmBox, FFI\CData $meta, float $nms) {
        return $this->darknetFFI->do_nms_sort($dects, $nmBox->cdata, $meta->classes, $nms);
    }

    protected function freeImage(FFI\CData $im) {
        return $this->darknetFFI->free_image($im);
    }

    protected function freeDetections(FFI\CData $dets, $num) {
        return $this->darknetFFI->free_detections($dets, $num->cdata);
    }

    /**
     * [free_network description]
     * @return [type]                  [description]
     */
    protected function freeNetwork() {
        return $this->darknetFFI->free_network($this->net);
    }

    protected function detectObject(FFI\CData $det, $key, $val) {
        return (object) ['label' => $val, 'confidence' => $det->prob[$key], 'box' => $this->bbox2obj($det->bbox)];
    }

    protected function bbox2Obj(FFI\CData $t) {
        return (object) ['x' => $t->x, 'y' => $t->y, 'w' => $t->w, 'h' => $t->h];
    }

    protected function gbBox(object $b) {
        $h = $b->h / 2;
        $w = $b->w / 2;
        $x = $b->x;
        $y = $b->y;
        return (object) ['x1' => $x - $w, 'y1' => $y - $h, 'x2' => $x + $w, 'y2' => $y + $h];
    }

    protected function drawBbox($inp, $outFile = null) {
        $this->rawImage = $this->createImg();
        imagesetthickness($this->rawImage, 2);
        if ($inp !== null) {
            foreach ($inp as $i) {
                $box = $this->gbBox($i->box);
                $label = $i->label . '-' . round($i->confidence, 2) . '%';
                switch ($i->label) {
                    case 'bear':
                    case 'cat':
                    case 'cow':
                    case 'dog':
                    case 'elephant':
                    case 'giraffe':
                    case 'horse':
                    case 'sheep':
                    case 'zebra':
                        $this->truthLabel($box, $label, 'yellow', 'black');
                        break;

                    case 'bird':
                        $this->truthLabel($box, $label, 'teal', 'white');
                        break;

                    case 'cake':
                    case 'donut':
                    case 'pizza':
                    case 'hot dog':
                    case 'carrot':
                    case 'broccoli':
                    case 'orange':
                    case 'sandwich':
                    case 'apple':
                    case 'banana':
                        $this->truthLabel($box, $label, 'magenta', 'white');
                        break;

                    case 'toaster':
                    case 'oven':
                    case 'microwave':
                    case 'cell phone':
                    case 'keyboard':
                    case 'remote':
                    case 'mouse':
                    case 'laptop':
                    case 'tvmonitor':
                    case 'refrigerator':
                        $this->truthLabel($box, $label, 'blue', 'white');
                        break;

                    case 'toothbrush':
                    case 'hair drier':
                    case 'vase':
                    case 'clock':
                    case 'bottle':
                    case 'spoon':
                    case 'cup':
                    case 'suitcase':
                    case 'tie':
                    case 'handbag':
                    case 'umbrella':
                    case 'book':
                    case 'backpack':
                    case 'wine glass':
                    case 'fork':
                    case 'bowl':
                    case 'knife':
                        $this->truthLabel($box, $label, 'purple', 'white');
                        break;

                    case 'diningtable':
                    case 'bed':
                    case 'bench':
                    case 'sofa':
                    case 'chair':
                    case 'sink':
                    case 'scissors':
                        $this->truthLabel($box, $label, 'cyan', 'black');
                        break;

                    case 'car':
                    case 'bus':
                    case 'boat':
                    case 'truck':
                    case 'bicycle':
                    case 'motorbike':
                    case 'aeroplane':
                        $this->truthLabel($box, $label, 'red', 'white');
                        break;
                    case 'person':
                        $this->truthLabel($box, $label, 'green', 'black');
                        break;

                    case 'skis':
                    case 'snowboard':
                    case 'sports ball':
                    case 'kite':
                    case 'baseball bat':
                    case 'baseball glove':
                    case 'skateboard':
                    case 'surfboard':
                    case 'tennis racket':
                    case 'teddy bear':
                        $this->truthLabel($box, $label, 'purple', 'white');
                        break;
                    
                    case 'traffic light':
                    case 'fire hydrant':
                    case 'stop sign':
                    case 'parking meter':
                        $this->truthLabel($box, $label, 'blueViolet', 'white');
                        break;
                    
                    default :
                        $this->truthLabel($box, $label, 'black', 'white');
                        break;
                }
            }
        } else {
            imagestring($this->rawImage, 5, 0, 20, "NULL [ Accuracy:- 00.00% ]", $this->color('black'));
        }
        if (is_null($outFile)) {
            return $this->img64Encode();
        } else {
            imagejpeg($this->rawImage, $outFile);
        }
        imagedestroy($this->rawImage);
    }

    /**
     * [color description]
     * @param  string $Colorname [description]
     * @return [type]            [description]
     */
    protected function color($Colorname = 'yellow') {

        switch ($Colorname) {
            case 'red':
                $color = imagecolorallocate($this->rawImage, 255, 0, 0);
                break;
            case 'green':
                $color = imagecolorallocate($this->rawImage, 0, 255, 0);
                break;
            case 'blue':
                $color = imagecolorallocate($this->rawImage, 0, 0, 255);
                break;
            case 'yellow':
                $color = imagecolorallocate($this->rawImage, 255, 255, 0);
                break;
            case 'magenta':
                $color = imagecolorallocate($this->rawImage, 255, 0, 255);
                break;
            case 'cyan':
                $color = imagecolorallocate($this->rawImage, 0, 255, 255);
                break;
            case 'purple':
                $color = imagecolorallocate($this->rawImage, 160, 32, 240);
                break;
            case 'teal':
                $color = imagecolorallocate($this->rawImage, 0, 128, 128);
                break;
            case 'blueViolet':
                $color = imagecolorallocate($this->rawImage, 138, 43, 226);
                break;
            case 'pink':
                $color = imagecolorallocate($this->rawImage, 255, 192, 203);
                break;
            case 'chocolate':
                $color = imagecolorallocate($this->rawImage, 210, 105, 30);
                break;
            case 'white':
                $color = imagecolorallocate($this->rawImage, 255, 255, 255);
                break;
            case 'aquamarine1':
                $color = imagecolorallocate($this->rawImage, 127, 255, 212);
                break;
            case 'azurel':
                $color = imagecolorallocate($this->rawImage, 240, 255, 255);
                break;
            case 'maroon':
                $color = imagecolorallocate($this->rawImage, 176, 48, 96);
                break;
            case 'orchid':
                $color = imagecolorallocate($this->rawImage, 218, 112, 214);
                break;
            case 'plum':
                $color = imagecolorallocate($this->rawImage, 221, 160, 221);
                break;
            case 'voilet':
                $color = imagecolorallocate($this->rawImage, 238, 130, 238);
                break;
            case 'salmon':
                $color = imagecolorallocate($this->rawImage, 250, 128, 114);
                break;
            case 'brown':
                $color = imagecolorallocate($this->rawImage, 165, 42, 42);
                break;
            case 'orange':
                $color = imagecolorallocate($this->rawImage, 255, 165, 0);
                break;
            case 'darkgreen':
                $color = imagecolorallocate($this->rawImage, 0, 100, 0);
                break;
            case 'navy':
                $color = imagecolorallocate($this->rawImage, 0, 0, 128);
                break;
            case 'slateblue':
                $color = imagecolorallocate($this->rawImage, 106, 90, 205);
                break;
            default :
                $color = imagecolorallocate($this->rawImage, 0, 0, 0);
                break;
        }
        return $color;
    }

    /**
     * [load_file description]
     * @param  [type] $filename [description]
     * @return [type]           [description]
     */
    protected function createImg() {
        switch ($this->imgInfo->type) {
            case 'png':
                $image = imagecreatefrompng($this->imgInfo->dir . DIRECTORY_SEPARATOR . $this->imgInfo->name);
                break;
            case 'jpg':
                $image = imagecreatefromjpeg($this->imgInfo->dir . DIRECTORY_SEPARATOR . $this->imgInfo->name);
                break;
            case 'bmp':
                $image = imagecreatefrombmp($this->imgInfo->dir . DIRECTORY_SEPARATOR . $this->imgInfo->name);
                break;
            case 'webp':
                $image = imagecreatefromwebp($this->imgInfo->dir . DIRECTORY_SEPARATOR . $this->imgInfo->name);
                break;

            default :
                throw new Exception('The image is not a resource or valid image file' . PHP_EOL);
                break;
        }
        return $image;
    }

    public function img4base64($url) {
        $url64 = $this->base64ImgDecode($url);
        if (!empty($url64)) {
            $im = imagecreatefromstring($url64);
            imagejpeg($im, __DIR__ . '/../temp/in/' . $this->tempImgName() . '.jpg', 100);
            imagedestroy($im);
            return __DIR__ . '/../temp/in/' . $this->temp_name . '.jpg';
        } else {
            return false;
        }
    }

    public function base64ImgDecode($url) {
        if ($url == 'data:,') {
            return null;
        } else {
            $data = str_replace("data:image/webp;base64", "", $url);
            return base64_decode($data);
        }
    }

    protected function img64Encode() {
        ob_start();
        imagewebp($this->rawImage, null, 100);
        $res = ob_get_contents();
        imagedestroy($this->rawImage);
        ob_end_clean();
        return "data:image/webp;base64," . base64_encode($res);
    }

    /**
     * [temp_imgName description]
     * @return [type] [description]
     */
    protected function tempImgName() {
        return $this->temp_name = ++$this->temp_name;
    }

    protected function consumedTime($startTime) {
        return microtime(true) - $startTime;
    }
    
    protected function fps($startTime) {
        return (1.0/(microtime(true) - $startTime));
    }

    protected function imgInfo($img) {
        list($w, $h) = getimagesize($img);
        $info = pathinfo($img);
        return $this->imgInfo = (object) ['w' => $w, 'h' => $h, 'name' => $info['basename'], 'type' => $info['extension'], 'dir' => $info['dirname']];
    }

    protected function imgResizeGD($img, $w = 416, $h = 416) {
        $ratio_orig = $this->image->w / $this->image->h;
        if ($w / $h > $ratio_orig) {
            $w = $h * $ratio_orig;
        } else {
            $h = $w / $ratio_orig;
        }

        $image_p = imagecreatetruecolor($w, $h);
        $image = imagecreatefromjpeg($img);
        imagecopyresampled($image_p, $image, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img));
        imagejpeg($image, __DIR__ . '/../temp/in/' . $this->tempImgName() . '.jpg', 100);
        imagedestroy($image_p);
        imagedestroy($image);
    }

    protected function truthLabel($box, $label, $bbcolor, $fncolor) {
        $strlen = strlen($label);
        $fw = $strlen * imagefontwidth(4);
        $fh = imagefontheight(4);
        imagefilledrectangle($this->rawImage, $box->x1 - 1, $box->y1 - $fh, $box->x1 + $fw, $box->y1, $this->color($bbcolor));
        imagerectangle($this->rawImage, $box->x1, $box->y1, $box->x2, $box->y2, $this->color($bbcolor));
        imagestring($this->rawImage, 4, $box->x1, $box->y1 - 16, $label, $this->color($fncolor));
        ;
    }

}
