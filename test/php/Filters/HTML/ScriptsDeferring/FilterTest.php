<?php

namespace Kibo\Phast\Filters\HTML\ScriptsDeferring;

use Kibo\Phast\Filters\HTML\HTMLFilterTestCase;

class FilterTest extends HTMLFilterTestCase {
    public function setUp() {
        parent::setUp();
        $this->filter = new Filter();
    }

    public function testRewriting() {
        $notInline  = $this->makeMarkedElement('script');
        $notInline->setAttribute('src', 'the-src');
        $notInline->setAttribute('defer', 'defer');
        $notInline->setAttribute('async', 'async');

        $inline = $this->makeMarkedElement('script');
        $inline->setAttribute('type', 'application/javascript');
        $inline->textContent = 'the-inline-content';

        $nonJS = $this->makeMarkedElement('script');
        $nonJS->setAttribute('type', 'non-js');

        $this->head->appendChild($notInline);
        $this->head->appendChild($inline);
        $this->head->appendChild($nonJS);

        $this->applyFilter();

        $notInline = $this->getMatchingElement($notInline);
        $inline = $this->getMatchingElement($inline);
        $nonJS = $this->getMatchingElement($nonJS);

        $this->assertEquals('text/phast', $notInline->getAttribute('type'));
        $this->assertEquals('the-src', $notInline->getAttribute('src'));
        $this->assertTrue($notInline->hasAttribute('defer'));
        $this->assertTrue($notInline->hasAttribute('async'));

        $this->assertEquals('text/phast', $inline->getAttribute('type'));
        $this->assertEquals('application/javascript', $inline->getAttribute('data-phast-original-type'));
        $this->assertFalse($inline->hasAttribute('async'));
        $this->assertEquals('the-inline-content', $inline->textContent);

        $this->assertEquals('non-js', $nonJS->getAttribute('type'));

        $this->assertHasCompiled('ScriptsDeferring/rewrite.js');
    }

    public function testDisableRewriting() {
        $script = $this->makeMarkedElement('script');
        $script->setAttribute('type', 'text/javascript');
        $script->setAttribute('src', 'the-src');
        $script->setAttribute('data-phast-no-defer', '');

        $this->head->appendChild($script);

        $this->applyFilter();

        $script = $this->getMatchingElement($script);
        $this->assertEquals('text/javascript', $script->getAttribute('type'));
        $this->assertFalse($script->hasAttribute('data-phast-no-defer'));
    }
}
