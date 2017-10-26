<?php

namespace Kibo\Phast\Factories\Filters\Image;

use Kibo\Phast\Exceptions\ItemNotFoundException;
use Kibo\Phast\Filters\Image\Image;
use Kibo\Phast\Filters\Image\ImageImplementations\GDImage;
use Kibo\Phast\Retrievers\LocalRetriever;
use Kibo\Phast\ValueObjects\URL;

class ImageFactory {

    private $config;

    public function __construct(array $config) {
        $this->config = $config;
    }

    /**
     * @param URL $url
     * @return Image
     */
    public function getForURL(URL $url) {
        $locator = new LocalRetriever($this->config['retrieverMap']);
        $string = $locator->retrieve($url);
        if ($string === false) {
            throw new ItemNotFoundException('Could not find image: ' . $url);
        }
        return new GDImage($string);
    }

}
