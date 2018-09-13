<?php

namespace Kibo\Phast\Filters\Image\ImageImplementations;

use Kibo\Phast\Common\ObjectifiedFunctions;
use Kibo\Phast\Exceptions\ItemNotFoundException;
use Kibo\Phast\Filters\Image\Exceptions\ImageProcessingException;
use Kibo\Phast\Filters\Image\Image;
use Kibo\Phast\Retrievers\Retriever;
use Kibo\Phast\ValueObjects\URL;
use PHPUnit\Framework\TestCase;

class GDImageTest extends TestCase {

    /**
     * @var ObjectifiedFunctions
     */
    private $functions;

    public function setUp() {
        parent::setUp();
        $this->functions = new ObjectifiedFunctions();
    }

    public function testImageSizeAndTypeForJPEG() {
        $this->checkImageSizeAndType(Image::TYPE_JPEG, 2 /* IMG_JPEG */, 'imagejpeg');
    }

    public function testImageSizeAndTypeForPNG() {
        $this->checkImageSizeAndType(Image::TYPE_PNG, 3, 'imagepng');
    }

    private function checkImageSizeAndType($type, $gdtype, $imgcallback) {
        $string = $this->getImageString($imgcallback);
        $image = $this->makeImage($string);

        $this->assertEquals(150, $image->getWidth());
        $this->assertEquals(200, $image->getHeight());
        $this->assertEquals($type, $image->getType());

        $image = $image->resize(75, 100);
        $this->assertEquals(75, $image->getWidth());
        $this->assertEquals(100, $image->getHeight());

        $resized = $image->getAsString();
        $info = getimagesizefromstring($resized);
        $this->assertEquals(75, $info[0]);
        $this->assertEquals(100, $info[1]);
        $this->assertEquals($gdtype, $info[2]);
    }

    public function testCompressingPNG() {
        $this->checkCompressing('imagepng', 1, 8);
    }

    public function testCompressingJPEG() {
        $this->checkCompressing('imagejpeg', 80, 20);
    }

    /**
     * @dataProvider recodingData
     */
    public function testRecoding($needFunction, $type, $expectedType, $expectedPattern) {
        if ($needFunction !== null && !function_exists($needFunction)) {
            $this->markTestSkipped("{$needFunction} is missing");
        }

        $jpeg = $this->getImageString('imagejpeg', 80, 'Hello, World');
        $image = $this->makeImage($jpeg);
        $recodedImage = $image->encodeTo($type);

        $this->assertEquals(Image::TYPE_JPEG, $image->getType());
        $this->assertEquals($type, $recodedImage->getType());

        $inputType = getimagesizefromstring($image->getAsString())[2];
        $this->assertEquals(IMAGETYPE_JPEG, $inputType);

        if ($expectedType !== null) {
            $outputType = getimagesizefromstring($recodedImage->getAsString())[2];
            $this->assertEquals($expectedType, $outputType);
        }

        if ($expectedPattern !== null) {
            $this->assertRegExp($expectedPattern, $recodedImage->getAsString());
        }
    }

    public function recodingData() {
        return [
            [null,        Image::TYPE_PNG,  IMAGETYPE_PNG, null],
            ['imagewebp', Image::TYPE_WEBP, null,          '/^RIFF/'],
        ];
    }

    public function testExceptionOnBadImageAsString() {
        $image = $this->makeImage('asdasd');
        $this->expectException(ImageProcessingException::class);
        $image->compress(9)->getAsString();
    }

    /**
     * @dataProvider imageInfoMethods
     */
    public function testExceptionOnBadImageInfo($method) {
        $image = $this->makeImage('asdasd');
        $caught = 0;
        for ($i = 0; $i < 2; $i++) {
            try {
                $image->$method();
            } catch (\Exception $e) {
                $caught++;
            }
        }
        $this->assertEquals(2, $caught, "Expected two exceptions when calling $method twice on a bad image");
    }

    public function imageInfoMethods() {
        return [
            ['getWidth'],
            ['getHeight'],
            ['getType']
        ];
    }

    public function testExceptionOnUnretrievableImage() {
        $image = $this->makeImage(false);
        $this->expectException(ItemNotFoundException::class);
        $image->getAsString();
    }

    public function testOriginalSizeAndImage() {
        $string = $this->getImageString('imagepng', 9, 'Hello, World!');
        $image = $this->makeImage($string);
        $this->assertEquals(strlen($string), $image->getSizeAsString());
        $this->assertSame($string, $image->getAsString());
    }

    /**
     * @dataProvider getExceptionsOnMissingFunctionsData
     */
    public function testExceptionsOnMissingFunctions($missing, $method, $params) {
        $this->functions->function_exists = function ($func) use ($missing) {
            return $func != $missing;
        };
        $image = $this->makeImage($this->getImageString('imagepng'));
        $this->expectException(ImageProcessingException::class);
        call_user_func_array([$image, $method], $params);
    }

    public function getExceptionsOnMissingFunctionsData() {
        return [
            ['imagecreatefromstring', 'resize', [20, 30]],
            ['imagecreatefromstring', 'compress', [1]],
            ['imagepng', 'encodeTo', [Image::TYPE_PNG]],
            ['imagejpeg', 'encodeTo', [Image::TYPE_JPEG]],
            ['imagewebp', 'encodeTo', [Image::TYPE_WEBP]]
        ];
    }

    private function checkCompressing($imagecb, $inputCompression, $outputCompression) {
        $string = $this->getImageString($imagecb, $inputCompression, 'Hello, World!');
        $image = $this->makeImage($string);

        $actual = $image->compress($outputCompression)->getAsString();
        $this->assertNotEmpty($actual);
        $this->assertLessThan(strlen($string), strlen($actual));
    }

    /**
     * @param $imageString
     * @return GDImage
     */
    private function makeImage($imageString) {
        $url = URL::fromString('http://kibo.test/the-image');
        $retriever = $this->createMock(Retriever::class);
        $retriever->method('retrieve')
                  ->with($url)
                  ->willReturn($imageString);
        return new GDImage($url, $retriever, $this->functions);
    }

    private function getImageString($callback, $compression = 0, $text = null) {
        if (!function_exists('imagecreate')) {
            $this->markTestSkipped('imagecreate (GD) is missing');
        }
        $image = imagecreate(150, 200);
        $orange = imagecolorallocate($image, 220, 210, 60);
        if ($text) {
            imagestring($image, 3, 10, 9, $text, $orange);
        }
        ob_start();
        $callback($image, null, $compression);
        return ob_get_clean();
    }

    public function testRefuseAnimatedGIF() {
        $data = file_get_contents(__DIR__ . '/../../../resources/animated.gif');
        if (!$data) {
            throw new \RuntimeException("Could not load animated.gif");
        }
        $image = $this->makeImage($data);
        $this->expectException(ImageProcessingException::class);
        $image->resize(100, 100)->getAsString();
    }

    public function testAcceptStillGIF() {
        if (!function_exists('imagecreate')) {
            $this->markTestSkipped('imagecreate (GD) is missing');
        }
        $data = file_get_contents(__DIR__ . '/../../../resources/still.gif');
        if (!$data) {
            throw new \RuntimeException("Could not load animated.gif");
        }
        $image = $this->makeImage($data);
        $newData = $image->resize(100, 100)->getAsString();
        $dimensions = getimagesizefromstring($newData);
        $this->assertEquals(100, $dimensions[0]);
    }

}
