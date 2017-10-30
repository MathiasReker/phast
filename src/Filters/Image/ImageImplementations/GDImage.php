<?php

namespace Kibo\Phast\Filters\Image\ImageImplementations;

use Kibo\Phast\Exceptions\ImageException;
use Kibo\Phast\Exceptions\ItemNotFoundException;
use Kibo\Phast\Filters\Image\Image;
use Kibo\Phast\Retrievers\Retriever;
use Kibo\Phast\ValueObjects\URL;

class GDImage extends BaseImage implements Image {

    /**
     * @var URL
     */
    private $imageURL;

    /**
     * @var Retriever
     */
    private $retriever;

    /**
     * @var bool
     */
    protected $processed = true;

    /**
     * @var string
     */
    private $imageString;

    /**
     * @var array
     */
    private $imageInfo;

    /**
     * GDImage constructor.
     *
     * @param URL $imageURL
     * @param Retriever $retriever
     */
    public function __construct(URL $imageURL, Retriever $retriever) {
        $this->imageURL = $imageURL;
        $this->retriever = $retriever;
    }

    public function getWidth() {
        return isset ($this->width) ? $this->width : $this->getImageInfo()[0];
    }

    public function getHeight() {
        return isset ($this->height) ? $this->height : $this->getImageInfo()[1];
    }

    public function getType() {
        $type = @image_type_to_mime_type($this->getImageInfo()[2]);
        if (!$type) {
            throw new ImageException('Could not determine image type');
        }
        return $type;
    }

    private function process() {
        try {
            $gdImage = @imagecreatefromstring($this->getImageString());
            if ($gdImage === false) {
                throw new ImageException('Could not load GD image');
            }
            if (isset ($this->width) && isset ($this->height)) {
                $gdImage = @imagescale($gdImage, $this->width, $this->height, IMG_BICUBIC);
                if ($gdImage === false) {
                    throw new ImageException('Could not resize GD image');
                }
            }
            @imagesavealpha($gdImage, true);
            if ($this->getType() == Image::TYPE_JPEG) {
                $callback = 'imagejpeg';
            } else {
                $callback = 'imagepng';
            }
            $tmpFh = @fopen('php://memory', 'w+');
            if (!$tmpFh) {
                throw new ImageException('Could not open temporary file');
            }
            if (isset ($this->compression)) {
                $ok = $callback($gdImage, $tmpFh, $this->compression);
            } else {
                $ok = $callback($gdImage, $tmpFh);
            }
            if (!$ok) {
                throw new ImageException('Could not write image to temporary file');
            }
            if (fseek($tmpFh, 0) !== 0) {
                throw new ImageException('Could not seek to beginning of temporary image file');
            }
            $string = stream_get_contents($tmpFh);
            if ($string === false) {
                throw new ImageException('Could not read image from temporary file');
            }
            return $string;
        } finally {
            if (isset ($gdImage)) {
                @imagedestroy($gdImage);
            }
            if (isset ($tmpFh)) {
                @fclose($tmpFh);
            }
        }
    }

    public function getAsString() {
        if (!$this->processed) {
            $this->imageString = $this->process();
            $this->processed = true;
        }
        return $this->getImageString();
    }

    private function getImageInfo() {
        if (!isset ($this->imageInfo)) {
            $this->imageInfo = @getimagesizefromstring($this->getImageString());
            if ($this->imageInfo === false) {
                throw new ImageException('Could not read GD image info');
            }
        }
        return $this->imageInfo;
    }

    private function getImageString() {
        if (!isset ($this->imageString)) {
            $this->imageString = $this->retriever->retrieve($this->imageURL);
            if ($this->imageString === false) {
                throw new ItemNotFoundException('Could not find image: ' . $this->imageURL, 0, null, $this->imageURL);
            }
        }
        return $this->imageString;
    }

    protected function __clone() {
        $this->processed = false;
    }

}
