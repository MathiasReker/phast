<?php

namespace Kibo\Phast\Filters\HTML;

use Kibo\Phast\Retrievers\Retriever;
use Kibo\Phast\ValueObjects\URL;

class CSSOptimizingHTMLFilterTest extends HTMLFilterTestCase {

    private $files;

    /**
     * @var CSSOptimizingHTMLFilter
     */
    private $filter;

    public function setUp() {
        parent::setUp();

        $this->filter = new CSSOptimizingHTMLFilter();
    }

    public function testOptimizingCSS() {
        $this->head->appendChild($this->dom->createElement('style', '
            .class1 { background: red; }
            .class2 { background: green; }
            .class3 { background: blue; }
            .class4 a[href] { color: white; }
        '));

        $this->head->appendChild($this->dom->createElement('style', '
            .class2 { background: yellow; }
        '));

        $div = $this->dom->createElement('div', 'Hello, World!');
        $div->setAttribute('class', 'some-class class1 another-class');
        $this->body->appendChild($div);

        $this->filter->transformHTMLDOM($this->dom);

        $styles = $this->getTheStyles();

        $this->assertCount(3, $styles);

        $this->assertContains('.class1', $styles[0]->textContent);
        $this->assertContains('red', $styles[0]->textContent);
        $this->assertContains('.class4', $styles[0]->textContent);
        $this->assertContains('white', $styles[0]->textContent);
        $this->assertNotContains('.class2', $styles[0]->textContent);
        $this->assertNotContains('green', $styles[0]->textContent);
        $this->assertNotContains('.class3', $styles[0]->textContent);
        $this->assertNotContains('blue', $styles[0]->textContent);

        $this->assertContains('.class1', $styles[1]->textContent);
        $this->assertContains('.class2', $styles[1]->textContent);
        $this->assertContains('.class3', $styles[1]->textContent);

        $this->assertSame($this->head, $styles[0]->parentNode);
        $this->assertSame($this->body, $styles[1]->parentNode);
        $this->assertSame($this->body, $styles[2]->parentNode);
        $this->assertSame($this->body->lastChild->previousSibling, $styles[1]);
        $this->assertSame($this->body->lastChild, $styles[2]);
    }

    public function testOptimizeChildRule() {
        $this->head->appendChild($this->dom->createElement('style', '
            .class1 { background: red; }
            a > .class2 { background: blue; }
        '));

        $div = $this->dom->createElement('div', 'Hello, World!');
        $div->setAttribute('class', 'some-class class1 another-class');
        $this->body->appendChild($div);

        $this->filter->transformHTMLDOM($this->dom);

        $styles = $this->getTheStyles();

        $this->assertContains('.class1', $styles[0]->textContent);
        $this->assertContains('red', $styles[0]->textContent);
        $this->assertNotContains('.class2', $styles[0]->textContent);
        $this->assertNotContains('blue', $styles[0]->textContent);
    }

    /**
     * @return \DOMElement[]
     */
    private function getTheStyles() {
        return iterator_to_array($this->dom->getElementsByTagName('style'));
    }

}