<?php

namespace Kibo\Phast\Filters\Service\Compression;


use Kibo\Phast\Common\ObjectifiedFunctions;
use Kibo\Phast\Exceptions\RuntimeException;
use Kibo\Phast\PhastTestCase;
use Kibo\Phast\ValueObjects\Resource;
use Kibo\Phast\ValueObjects\URL;

class CompressingFilterTest extends PhastTestCase {

    public function setUp() {
        parent::setUp();
        if (!function_exists('gzencode')) {
            $this->markTestSkipped('gzencode function not found');
        }
    }

    public function testApply() {
        $resource = Resource::makeWithContent(URL::fromString(self::BASE_URL), 'some-content', 'text/css');
        $filter = new CompressingFilter();
        $compressed = $filter->apply($resource, []);
        $this->assertEquals(gzencode('some-content'), $compressed->getContent());
        $this->assertEquals($resource->getMimeType(), $compressed->getMimeType());
        $this->assertEquals('gzip', $compressed->getEncoding());
    }

    public function testExceptionOnMissingFunction() {
        $funcs = new ObjectifiedFunctions();
        $funcs->function_exists = function ($func) {
            if ($func == 'gzencode') {
                return false;
            }
            return true;
        };
        $resource = Resource::makeWithContent(URL::fromString(self::BASE_URL), 'some-content', 'text/css');
        $filter = new CompressingFilter($funcs);
        $this->expectException(RuntimeException::class);
        $filter->apply($resource, []);
    }

}