<?php

namespace Kibo\Phast\Filters\HTML\PhastScriptsCompiler;


use Kibo\Phast\Cache\Cache;
use Kibo\Phast\Common\ObjectifiedFunctions;
use Kibo\Phast\ValueObjects\PhastJavaScript;
use PHPUnit\Framework\TestCase;

class PhastJavaScriptCompilerTest extends TestCase {

    private $cache;

    /**
     * @var PhastJavaScriptCompiler
     */
    private $compiler;

    public function setUp() {
        parent::setUp();
        $this->cache = $this->createMock(Cache::class);
        $this->cache->method('get')
            ->willReturnCallback(function ($key, callable $cb) {
                return $cb();
            });

        $this->compiler = new PhastJavaScriptCompiler($this->cache, '');
    }

    public function testCompileScripts() {
        $funcs1 = new ObjectifiedFunctions();
        $funcs1->file_get_contents = function () {
            return 'var    a;';
        };
        $funcs1->filemtime = function () {
            return 123;
        };
        $funcs2 = new ObjectifiedFunctions();
        $funcs2->file_get_contents = function () {
            return 'var    b;';
        };
        $funcs2->filemtime = function () {
            return 123;
        };
        $scripts = [new PhastJavaScript('f1', $funcs1), new PhastJavaScript('f2', $funcs2)];
        $compiled = $this->compiler->compileScripts($scripts);

        $this->assertStringStartsWith('function phastScripts', $compiled);
        $this->assertStringEndsWith('}', $compiled);

        $expectedScript1 = '(function(){var a;})';
        $expectedScript2 = '(function(){var b;})';
        $this->assertContains($expectedScript1, $compiled);
        $this->assertContains($expectedScript2, $compiled);
    }

    public function testCompilingWithConfig() {
        $funcs1 = new ObjectifiedFunctions();
        $funcs1->file_get_contents = function () {
            return 'var a;';
        };
        $funcs1->filemtime = function () {
            return 123;
        };
        $script = new PhastJavaScript('f1', $funcs1);
        $script->setConfig('configKey1', ['item' => 'value']);
        $compiled = $this->compiler->compileScriptsWithConfig([$script]);

        $this->assertStringStartsWith('(', $compiled);
        $this->assertStringEndsWith('"configKey1":{"item":"value"}}});', $compiled);
    }

    public function testCaching() {
        $keys = [];
        $this->cache = $this->createMock(Cache::class);
        $this->cache->expects($this->exactly(4))
            ->method('get')
            ->willReturnCallback(function ($key, callable $cb) use (&$keys) {
                $keys[] = $key;
                return 'cached';
            });

        $funcs1 = new ObjectifiedFunctions();
        $funcs1->filemtime = function () {
            return 123;
        };
        $funcs2 = new ObjectifiedFunctions();
        $funcs2->filemtime = function () {
            return 234;
        };

        $s1 = new PhastJavaScript('f1', $funcs1);
        $s2 = new PhastJavaScript('f2', $funcs1);
        $s3 = new PhastJavaScript('f2', $funcs2);

        $compiler = new PhastJavaScriptCompiler($this->cache, '');
        $this->assertEquals('cached', $compiler->compileScripts([$s1, $s2]));
        $this->assertEquals('cached', $compiler->compileScripts([$s1, $s2]));
        $this->assertEquals('cached', $compiler->compileScripts([$s2]));
        $this->assertEquals('cached', $compiler->compileScripts([$s3]));

        $this->assertEquals($keys[0], $keys[1]);
        $this->assertNotEquals($keys[1], $keys[2]);
        $this->assertNotEquals($keys[2], $keys[3]);
    }
}
