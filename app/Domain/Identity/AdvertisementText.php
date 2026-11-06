<?php

namespace App\Domain\Identity;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

final class AdvertisementText
{
    private const FIELDS = ['temporary_appointment', 'pay_scale', 'age_writeup', 'language_writeup',
        'essential_writeup', 'desirable_writeup', 'marks_writeup', 'miscellaneous_writeup', 'disability_writeup'];

    public static function normalize(array $data): array
    {
        foreach (self::FIELDS as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $text = self::plainText($data[$field]);
                $data[$field] = $text === '' ? null : $text;
            }
        }

        return $data;
    }

    private static function plainText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML('<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $text = self::nodeText($document);
        $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[^\S\n]+/u', ' ', $text);
        $text = preg_replace('/ *\n */u', "\n", $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }

    private static function nodeText(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $node->nodeValue;
        }
        $name = $node instanceof DOMElement ? strtolower($node->tagName) : '';
        if (in_array($name, ['script', 'style', 'head', 'template', 'noscript'], true)) {
            return '';
        }
        if ($name === 'br') {
            return "\n";
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= self::nodeText($child);
        }
        if (in_array($name, ['p', 'div', 'li', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'tr', 'td', 'th', 'table', 'section', 'article', 'blockquote'], true)) {
            $text .= "\n";
        }

        return $text;
    }
}
