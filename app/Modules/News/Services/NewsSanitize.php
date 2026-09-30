<?php

declare(strict_types=1);

namespace App\Modules\News\Services;

use DOMCdataSection;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Server-side sanitizer for the article body `isi` (spec §13): the homepage
 * renders `content` with v-html and adds no sanitizer of its own, so the raw
 * client HTML is never stored.
 *
 * Allow-list only, built on ext-dom (no new dependency):
 * - scripts, styles, frames, forms, SVG/MathML are removed WITH their content;
 * - any other unknown tag is unwrapped (its text is kept);
 * - only the attributes listed per tag survive (so every on* handler, style
 *   and class goes), and href/src must be http(s) (plus mailto/tel for links)
 *   or a relative URL — `javascript:`/`data:` are dropped;
 * - comments and processing instructions are removed.
 */
class NewsSanitize
{
    /** tag => allowed attributes */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'hr' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'h2' => [], 'h3' => [], 'h4' => [],
        'ul' => [], 'ol' => [], 'li' => [],
        'blockquote' => [],
        'figure' => [], 'figcaption' => [],
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'width', 'height'],
    ];

    /** Removed together with everything inside them. */
    private const DROP = [
        'script' => true, 'style' => true, 'iframe' => true, 'frame' => true, 'frameset' => true,
        'object' => true, 'embed' => true, 'applet' => true, 'noscript' => true, 'template' => true,
        'form' => true, 'input' => true, 'button' => true, 'select' => true, 'textarea' => true,
        'svg' => true, 'math' => true, 'link' => true, 'meta' => true, 'base' => true, 'head' => true, 'title' => true,
    ];

    public function clean(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementsByTagName('div')->item(0);
        if ($root === null) {
            return '';
        }
        $this->walk($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMCdataSection) {
                $node->replaceChild($node->ownerDocument->createTextNode($child->data), $child);

                continue;
            }
            if ($child instanceof DOMText) {
                continue;
            }
            if (! $child instanceof DOMElement) {
                $node->removeChild($child); // comments, processing instructions

                continue;
            }

            $tag = strtolower($child->tagName);
            if (isset(self::DROP[$tag])) {
                $node->removeChild($child);

                continue;
            }
            $this->walk($child);

            if (! isset(self::ALLOWED[$tag])) {
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                if (! in_array($name, self::ALLOWED[$tag], true)
                    || (($name === 'href' || $name === 'src') && ! $this->safeUrl($attr->value, $name))) {
                    $child->removeAttribute($attr->name);
                }
            }
            if ($tag === 'a' && $child->hasAttribute('href')) {
                $child->setAttribute('rel', 'noopener noreferrer nofollow');
            }
            if ($tag === 'img' && ! $child->hasAttribute('src')) {
                $node->removeChild($child);
            }
        }
    }

    /** http(s) everywhere, mailto/tel on links, or a relative URL. */
    private function safeUrl(string $value, string $attr): bool
    {
        // Browsers ignore control chars and whitespace inside the scheme ("java\tscript:").
        $v = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $value));
        if ($v === '') {
            return false;
        }
        if (! preg_match('/^([a-z][a-z0-9+.\-]*):/', $v, $m)) {
            return true; // relative, #anchor or //host
        }
        $allowed = $attr === 'href' ? ['http', 'https', 'mailto', 'tel'] : ['http', 'https'];

        return in_array($m[1], $allowed, true);
    }
}
