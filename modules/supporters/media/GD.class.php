<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

class GD
{
    public int $width;

    public int $height;

    public int $type;

    private int $conversion_type;

    private $image;

    private $info;

    private int $quality;

    public function __construct($file = '')
    {
        if (!extension_loaded('gd') && !extension_loaded('gd2')) {
            return false;
        }

        $this->clear();

        if (!empty($file)) {
            $this->load($file);
        }

        return true;
    }

    public function clear()
    {
        if ($this->image) {
            imagedestroy($this->image);
        }

        $this->image = null;
        $this->width = 0;
        $this->height = 0;
        $this->quality = 100;
        $this->info = null;
        $this->type = IMAGETYPE_UNKNOWN;
        $this->conversion_type = IMAGETYPE_UNKNOWN;
    }

    public function load($file)
    {
        $string = @file_get_contents($file);

        return $this->load_from_string($string);
    }

    public function load_from_string($string)
    {
        $this->clear();

        if (empty($string)) {
            return false;
        }

        if (PHP_VERSION_ID < 70300 && function_exists('imagecreatefromwebp') && $this->isWebP($string)) {
            $this->image = imagecreatefromwebp('data:image/webp;base64,' . base64_encode($string));
        }
        else {
            $this->image = imagecreatefromstring($string);
        }

        list($this->width, $this->height, $this->type) = getimagesizefromstring($string, $imageinfo);

        if (!$this->width or !$this->image) {
            $this->clear();
            return false;
        }

        if (!empty($imageinfo)) {

            if (isset($imageinfo["APP1"])) {
                $exiflength = strlen($imageinfo['APP1']) + 2;
                if ($exiflength <= 0xFFFF) {
                    // Construct EXIF segment
                    $this->info['exif'] = chr(0xFF) . chr(0xE1) . chr(($exiflength >> 8) & 0xFF) . chr($exiflength & 0xFF) . $imageinfo['APP1'];
                }
            }

            // Prepare IPTC data bytes from source file
            if (isset($imageinfo["APP13"])) {
                $iptclength = strlen($imageinfo['APP13']) + 2;
                if ($iptclength <= 0xFFFF) {
                    // Construct IPTC segment
                    $this->info['iptc'] = chr(0xFF) . chr(0xED) . chr(($iptclength >> 8) & 0xFF) . chr($iptclength & 0xFF) . $imageinfo['APP13'];
                }
            }
        }

        return true;
    }

    /**
     * Check if the raw image data represents an image in WebP format.
     *
     * @param string $data
     *
     * @return bool
     */
    private function isWebP(&$data)
    {
        return substr($data, 8, 7) === 'WEBPVP8';
    }

    public function getImageMimeType()
    {
        $type = $this->conversion_type ? $this->conversion_type : $this->type;

        switch ($type) {
            case IMAGETYPE_JPEG:
                $imageType = "image/jpeg";
                break;

            case IMAGETYPE_PNG:
                $imageType = "image/png";
                break;

            case IMAGETYPE_BMP:
                $imageType = "image/bmp";
                break;

            case IMAGETYPE_XBM:
                $imageType = "image/xbm";
                break;

            case IMAGETYPE_WBMP:
                $imageType = "image/wbmp";
                break;

            case IMAGETYPE_WEBP:
                $imageType = "image/webp";
                break;

            case IMAGETYPE_GIF:
                $imageType = "image/gif";
                break;

            default:
                $imageType = "";
        }

        return $imageType;
    }

    public function setImageCompressionQuality($quality)
    {
        $this->quality = $quality;
    }

    public function setImageFormat($format)
    {
        $this->conversion_type = $this->extension_to_imageType($format);
    }

    private function extension_to_imageType($extension, $unknown = IMAGETYPE_UNKNOWN)
    {
        $extension = strtolower($extension);

        switch ($extension) {

            case 'jpg':
            case 'jpeg':
            case 'pjpeg':
            case IMAGETYPE_JPEG:
                $imageType = IMAGETYPE_JPEG;
                break;

            case 'png':
            case IMAGETYPE_PNG:
                $imageType = IMAGETYPE_PNG;
                break;

            case 'xbm':
            case IMAGETYPE_XBM:
                $imageType = IMAGETYPE_XBM;
                break;

            case 'wbmp':
            case IMAGETYPE_WBMP:
                $imageType = IMAGETYPE_WBMP;
                break;

            case 'webp':
            case IMAGETYPE_WEBP:
                $imageType = IMAGETYPE_WEBP;
                break;

            case 'bmp':
            case IMAGETYPE_BMP:
                $imageType = IMAGETYPE_BMP;
                break;

            case 'gif':
            case IMAGETYPE_GIF:
                $imageType = IMAGETYPE_GIF;
                break;

            default:
                $imageType = $unknown;
        }

        return $imageType;
    }

    public function stripImage()
    {
        $this->info = null;
    }

    public function writeImage($image_path, $cleanup = true)
    {
        $type = $this->conversion_type ? $this->conversion_type : $this->type;

        switch ($type) {

            case IMAGETYPE_GIF:
                $return = imagegif($this->image, $image_path);
                break;

            case IMAGETYPE_PNG:
                $return = imagepng($this->image, $image_path, ceil($this->quality * 9 / 100));
                break;

            case IMAGETYPE_WBMP:
                $return = imagewbmp($this->image, $image_path);
                break;

            case IMAGETYPE_BMP:
                $return = imagebmp($this->image, $image_path, true);
                break;

            case IMAGETYPE_XBM:
                $return = imagexbm($this->image, $image_path);
                break;

            case IMAGETYPE_WEBP:
                $return = imagewebp($this->image, $image_path, $this->quality);
                break;

            default:
                $return = imagejpeg($this->image, $image_path, $this->quality);
                break;
        }

        if ($return and $type === IMAGETYPE_JPEG and !empty($this->info)) {

            $content = file_get_contents($image_path);

            $content = $this->pack_exif_iptc(
                $content,
                $this->info['exif'] ?? null,
                $this->info['iptc'] ?? null
            );

            if ($content) {
                $return = file_put_contents($image_path, $content);
            }
        }

        if ($cleanup) {
            $this->clear();
        }

        return $return;
    }

    private function pack_exif_iptc($image, $exif = null, $iptc = null)
    {
        $destfilecontent = substr($image, 2);
        // Variable accumulates new & original IPTC application segments
        $portiontoadd = chr(0xFF) . chr(0xD8);
        $exifadded = empty($exif);
        $iptcadded = empty($iptc);

        while ((substr($destfilecontent, 0, 2) & 0xFFF0) === 0xFFE0) {

            $segmentlen = (substr($destfilecontent, 2, 2) & 0xFFFF);
            // Last 4 bits of second byte is IPTC segment #
            $iptcsegmentnumber = (substr($destfilecontent, 1, 1) & 0x0F);
            if ($segmentlen <= 2) {
                return false;
            }

            $thisexistingsegment = substr($destfilecontent, 0, $segmentlen + 2);

            if ((!$exifadded) and (1 <= $iptcsegmentnumber)) {
                $portiontoadd .= $exif;
                $exifadded = true;
                if (1 === $iptcsegmentnumber) {
                    $thisexistingsegment = '';
                }
            }
            if ((!$iptcadded) and (13 <= $iptcsegmentnumber)) {
                $portiontoadd .= $iptc;
                $iptcadded = true;
                if (13 === $iptcsegmentnumber) {
                    $thisexistingsegment = '';
                }
            }
            $portiontoadd .= $thisexistingsegment;
            $destfilecontent = substr($destfilecontent, $segmentlen + 2);
        }
        if (!$exifadded) {
            $portiontoadd .= $exif;  //  Add EXIF data if not added already
        }
        if (!$iptcadded) {
            $portiontoadd .= $iptc;  //  Add IPTC data if not added already
        }

        return $portiontoadd . $destfilecontent;
    }

    /**
     * Set image resource (after using a raw gd command)
     *
     * @param $resource
     * @param int $type
     * @param null $info array(exif => ..., iptc => ...)
     * @return bool
     */
    public function setResource($resource, int $type, $info = null)
    {
        if (!$this->is_gd_image($resource)) {
            return false;
        }

        $this->image = $resource;
        $this->width = imagesx($resource);
        $this->height = imagesy($resource);
        $this->quality = 100;
        $this->info = $info;
        $this->type = $type;
        return true;
    }

    public function is_gd_image($image)
    {
        return ((is_resource($image) and 'gd' === get_resource_type($image)) or (is_object($image) and $image instanceof \GdImage));
    }

    public function scaleImage($width, $height, $bestfit = false)
    {
        if ($this->width <= $width and $this->height <= $height) {
            return false;
        }

        if ($bestfit) {
            // Calculate ratio of desired maximum sizes and original sizes.
            $widthRatio = $width / $this->width;
            $heightRatio = $height / $this->height;

            // Ratio used for calculating new image dimensions.
            $ratio = min($widthRatio, $heightRatio);

            // Calculate new image dimensions.
            $newWidth = intval($this->width * $ratio);
            $newHeight = intval($this->height * $ratio);

            if (function_exists('imagecreatetruecolor')) {
                $scaledImage = imagecreatetruecolor($newWidth, $newHeight);
            }
            else {
                $scaledImage = imagecreate($newWidth, $newHeight);
            }

            if (function_exists('imagealphablending') and function_exists('imagesavealpha')) {
                $transparent = imagecolorallocatealpha($scaledImage, 0, 0, 0, 127);
                imagefill($scaledImage, 0, 0, $transparent);
                imagesavealpha($scaledImage, true);
            }

            imagecopyresampled($scaledImage, $this->image, 0, 0, 0, 0, $newWidth, $newHeight, $this->width, $this->height);

            // Free up the memory.
            imagedestroy($this->image);

            $this->image = $scaledImage;
        }
        else {
            imagescale($this->image, $width, $height, IMG_BILINEAR_FIXED);
        }

        $this->width = $width;
        $this->height = $height;

        return true;
    }
}