<?php

namespace Kibo\Phast\Parsing\HTML;


use Kibo\Phast\Parsing\HTML\HTMLStreamElements\ClosingTag;
use Kibo\Phast\Parsing\HTML\HTMLStreamElements\Element;
use Kibo\Phast\Parsing\HTML\HTMLStreamElements\Tag;

class PCRETokenizerTest extends \PHPUnit_Framework_TestCase {

    public function testSimpleDocument() {
        $html = "
            <!doctype html>
            <html>
            <head>
            <title>Hello, World!</title>
            </head>
            <body>
            </body>
            </html>
        ";

        $tokenizer = new PCRETokenizer();
        $tokens = $tokenizer->tokenize($html);

        $this->checkTokens([
            [Tag::class, '<!doctype html>'],
            [Tag::class, '<html>'],
            [Tag::class, '<head>'],
            [Tag::class, '<title>'],
            [Element::class, 'Hello, World!'],
            [ClosingTag::class, '</title>'],
            [ClosingTag::class, '</head>'],
            [Tag::class, '<body>'],
            [ClosingTag::class, '</body>'],
            [ClosingTag::class, '</html>']
        ], $tokens);
    }

    private function checkTokens(array $expected, \Traversable $actual) {
        foreach ($actual as $token) {
            if ($token instanceof Element && trim((string) $token) === '') {
                continue;
            }
            $this->assertNotEmpty($expected);
            $expectedToken = array_shift($expected);
            $this->assertEquals($expectedToken, [get_class($token), (string) $token]);
        }
        $this->assertEmpty($expected);
    }

    public function testAttributeStyles() {
        $html = '<div a=1 b="2" c=\'3\' d = 4 e = "5" ==6 f=`7`>';

        $tag = $this->tokenizeSingleTag($html);

        $this->assertEquals('1', $tag->getAttribute('a'));
        $this->assertEquals('2', $tag->getAttribute('b'));
        $this->assertEquals('3', $tag->getAttribute('c'));
        $this->assertEquals('4', $tag->getAttribute('d'));
        $this->assertEquals('5', $tag->getAttribute('e'));
        $this->assertEquals('6', $tag->getAttribute('='));
        $this->assertEquals('`7`', $tag->getAttribute('f'));
    }

    public function testJoinedAttributes() {
        $html = '<div a="1"b=2>';

        $tag = $this->tokenizeSingleTag($html);

        $this->assertEquals('1', $tag->getAttribute('a'));
        $this->assertEquals('2', $tag->getAttribute('b'));
    }

    public function testCase() {
        $this->markTestIncomplete("Where should we handle case normalization?");

        $html = '<DiV AttR="A">';

        $tag = $this->tokenizeSingleTag($html);

        $this->assertEquals('div', $tag->getTagName());
        $this->assertEquals('A', $tag->getAttribute('attr'));
    }

    /**
     * @param $html
     * @return Tag
     */
    private function tokenizeSingleTag($html) {
        $tokenizer = new PCRETokenizer();
        $tokens = iterator_to_array($tokenizer->tokenize($html));

        $this->assertCount(1, $tokens);

        /** @var Tag $tag */
        $tag = $tokens[0];

        $this->assertInstanceOf(Tag::class, $tag);

        return $tag;
    }

}
