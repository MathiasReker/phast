<?php

namespace Kibo\Phast\Filters\HTML;

use Kibo\Phast\Filters\HTML\Helpers\BodyFinderTrait;

class CSSOptimizingHTMLFilter implements HTMLFilter {
    use BodyFinderTrait;

    private $classes;

    public function transformHTMLDOM(\DOMDocument $document) {
        $body = $this->getBodyElement($document);
        $styles = iterator_to_array($document->getElementsByTagName('style'));

        $this->classes = $this->getUsedClasses($document);

        foreach ($styles as $style) {
            if (!$this->isStyle($style)) {
                continue;
            }

            $optimized = $this->optimizeStyle($style);

            if ($optimized) {
                $style->parentNode->insertBefore($optimized, $style);
            }

            $body->appendChild($style);
        }
    }

    private function getUsedClasses(\DOMDocument $document) {
        $xpath = new \DOMXPath($document);
        $classes = [];

        foreach ($xpath->query('//*/@class') as $class) {
            foreach (preg_split('/\s+/', $class->value) as $cls) {
                if ($cls != '') {
                    $classes[$cls] = true;
                }
            }
        }

        return $classes;
    }

    private function isStyle(\DOMElement $style) {
        $type = $style->getAttribute('type');

        if ($type != '' && $type != 'text/css') {
            return false;
        }

        return true;
    }

    private function optimizeStyle(\DOMElement $style) {
        $re_simple_selector_chars = "[A-Z0-9_.#*:()>+\~\s-]";
        $re_selector = "(?: $re_simple_selector_chars | \[[a-z]+\] )+";
        $re_rule = "~
                (?<= ^ | [;}] ) \s*
                ( (?: $re_selector , )* $re_selector )
                ( { [^}]* } )
        ~xi";

        $css = $style->textContent;
        $css = preg_replace_callback(
            $re_rule,
            function ($match) {
                return $this->optimizeRule($match[1], $match[2]);
            },
            $css
        );
        $css = trim($css);

        if ($css == '') {
            return;
        }

        $optimized = $style->ownerDocument->createElement('style', $css);
        $optimized->setAttribute('media', $style->getAttribute('media'));

        return $optimized;
    }

    private function optimizeRule($selectors, $body) {
        $new_selectors = [];

        foreach (explode(',', $selectors) as $selector) {
            if ($this->selectorCouldMatch($selector)) {
                $new_selectors[] = $selector;
            }
        }

        if ($new_selectors) {
            return implode(',', $new_selectors) . $body;
        }

        return '';
    }

    private function selectorCouldMatch($selector) {
        preg_match_all('~\.(-?[_A-Z]+[_A-Z0-9-]*)~xi', $selector, $matches);

        foreach ($matches[1] as $class) {
            if (!isset($this->classes[$class])) {
                return false;
            }
        }

        return true;
    }

}
