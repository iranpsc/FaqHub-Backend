<?php

namespace App\Services;

/**
 * HTML Sanitization Service
 *
 * Sanitizes user-generated HTML content to prevent XSS attacks.
 * This provides server-side sanitization as a defense-in-depth measure.
 */
class HtmlSanitizer
{
    /**
     * Allowed HTML tags and their permitted attributes
     */
    protected array $allowedTags = [
        // Text formatting
        'p' => ['class', 'style'],
        'br' => [],
        'span' => ['class', 'style'],
        'div' => ['class', 'style'],

        // Headers
        'h1' => ['class', 'style'],
        'h2' => ['class', 'style'],
        'h3' => ['class', 'style'],
        'h4' => ['class', 'style'],
        'h5' => ['class', 'style'],
        'h6' => ['class', 'style'],

        // Text styling
        'strong' => ['class'],
        'b' => ['class'],
        'em' => ['class'],
        'i' => ['class'],
        'u' => ['class'],
        's' => ['class'],
        'strike' => ['class'],
        'del' => ['class'],
        'ins' => ['class'],
        'sub' => [],
        'sup' => [],

        // Lists
        'ul' => ['class', 'style'],
        'ol' => ['class', 'style', 'start', 'type'],
        'li' => ['class', 'style'],

        // Links
        'a' => ['href', 'title', 'target', 'rel', 'class'],

        // Images
        'img' => ['src', 'alt', 'title', 'width', 'height', 'class', 'style'],
        'figure' => ['class', 'style'],
        'figcaption' => ['class', 'style'],

        // Tables
        'table' => ['class', 'style', 'border', 'cellpadding', 'cellspacing'],
        'thead' => ['class'],
        'tbody' => ['class'],
        'tfoot' => ['class'],
        'tr' => ['class', 'style'],
        'th' => ['class', 'style', 'colspan', 'rowspan', 'scope'],
        'td' => ['class', 'style', 'colspan', 'rowspan'],

        // Quotes and code
        'blockquote' => ['class', 'style'],
        'pre' => ['class', 'style'],
        'code' => ['class', 'style'],

        // Other safe elements
        'hr' => ['class'],
        'address' => ['class'],
        'cite' => ['class'],
        'abbr' => ['title', 'class'],
        'mark' => ['class'],
    ];

    /**
     * Tags that should be completely removed along with their content
     */
    protected array $stripTags = [
        'script', 'style', 'noscript', 'iframe', 'object', 'embed',
        'form', 'input', 'button', 'select', 'textarea', 'applet',
        'meta', 'link', 'base', 'frame', 'frameset',
    ];

    /**
     * Dangerous URL protocols
     */
    protected array $dangerousProtocols = [
        'javascript:', 'vbscript:', 'data:', 'file:',
    ];

    /**
     * Allowed CSS properties for inline styles
     */
    protected array $allowedCssProperties = [
        'color', 'background-color', 'font-size', 'font-weight', 'font-style',
        'font-family', 'text-align', 'text-decoration', 'line-height',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-color', 'border-width', 'border-style',
        'width', 'height', 'max-width', 'max-height', 'min-width', 'min-height',
        'display', 'float', 'clear', 'vertical-align',
        'list-style-type', 'list-style',
    ];

    /**
     * Sanitize HTML content
     */
    public function sanitize(?string $html): string
    {
        if (empty($html)) {
            return '';
        }

        // First, strip dangerous tags completely (including their content)
        $html = $this->stripDangerousTags($html);

        // Parse and rebuild HTML
        $html = $this->parseAndRebuild($html);

        return $html;
    }

    /**
     * Strip dangerous tags and their content
     */
    protected function stripDangerousTags(string $html): string
    {
        foreach ($this->stripTags as $tag) {
            // Remove tag and its content
            $html = preg_replace(
                '/<'.$tag.'\b[^>]*>.*?<\/'.$tag.'>/is',
                '',
                $html
            );
            // Remove self-closing versions
            $html = preg_replace(
                '/<'.$tag.'\b[^>]*\/?>/is',
                '',
                $html
            );
        }

        return $html;
    }

    /**
     * Parse HTML and rebuild with only allowed tags and attributes
     */
    protected function parseAndRebuild(string $html): string
    {
        // Use DOMDocument for proper HTML parsing
        $dom = new \DOMDocument('1.0', 'UTF-8');

        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);

        // Wrap in a container to handle fragments
        $wrappedHtml = '<div id="sanitizer-wrapper">'.$html.'</div>';

        // Load HTML with UTF-8 encoding
        $dom->loadHTML(
            '<?xml encoding="UTF-8">'.$wrappedHtml,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();

        // Process all elements
        $this->processNode($dom->documentElement);

        // Get the sanitized HTML from our wrapper
        $wrapper = $dom->getElementById('sanitizer-wrapper');
        if ($wrapper) {
            $result = '';
            foreach ($wrapper->childNodes as $child) {
                $result .= $dom->saveHTML($child);
            }

            return $result;
        }

        return '';
    }

    /**
     * Recursively process DOM nodes
     */
    protected function processNode(\DOMNode $node): void
    {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return;
        }

        /** @var \DOMElement $node */
        $tagName = strtolower($node->tagName);

        // Check if tag is allowed
        if (! isset($this->allowedTags[$tagName]) && $tagName !== 'div') {
            // Remove disallowed tags but keep their text content
            $this->unwrapNode($node);

            return;
        }

        // Process attributes
        $this->sanitizeAttributes($node, $tagName);

        // Process child nodes (iterate in reverse to handle removals)
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            $this->processNode($child);
        }
    }

    /**
     * Remove a node but keep its children
     */
    protected function unwrapNode(\DOMNode $node): void
    {
        $parent = $node->parentNode;
        if (! $parent) {
            return;
        }

        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }

    /**
     * Sanitize element attributes
     */
    protected function sanitizeAttributes(\DOMElement $element, string $tagName): void
    {
        $allowedAttrs = $this->allowedTags[$tagName] ?? [];

        // Special handling for wrapper div
        if ($tagName === 'div' && $element->getAttribute('id') === 'sanitizer-wrapper') {
            return;
        }

        // Collect attributes to remove (can't modify during iteration)
        $toRemove = [];

        foreach ($element->attributes as $attr) {
            $attrName = strtolower($attr->name);
            $attrValue = $attr->value;

            // Remove event handlers
            if (str_starts_with($attrName, 'on')) {
                $toRemove[] = $attr->name;

                continue;
            }

            // Check if attribute is allowed
            if (! in_array($attrName, $allowedAttrs)) {
                $toRemove[] = $attr->name;

                continue;
            }

            // Sanitize URL attributes
            if (in_array($attrName, ['href', 'src'])) {
                $sanitizedUrl = $this->sanitizeUrl($attrValue, $tagName === 'img');
                if ($sanitizedUrl === null) {
                    $toRemove[] = $attr->name;
                } else {
                    $element->setAttribute($attr->name, $sanitizedUrl);
                }

                continue;
            }

            // Sanitize style attribute
            if ($attrName === 'style') {
                $sanitizedStyle = $this->sanitizeStyle($attrValue);
                if (empty($sanitizedStyle)) {
                    $toRemove[] = $attr->name;
                } else {
                    $element->setAttribute($attr->name, $sanitizedStyle);
                }

                continue;
            }

            // Ensure target="_blank" has rel="noopener noreferrer"
            if ($attrName === 'target' && $attrValue === '_blank') {
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }

        // Remove disallowed attributes
        foreach ($toRemove as $attrName) {
            $element->removeAttribute($attrName);
        }
    }

    /**
     * Sanitize URL values
     */
    protected function sanitizeUrl(string $url, bool $allowDataImages = false): ?string
    {
        $url = trim($url);

        // Allow empty URLs (for placeholder images)
        if (empty($url)) {
            return $url;
        }

        $lowerUrl = strtolower($url);

        // Allow data:image/* for images
        if ($allowDataImages && str_starts_with($lowerUrl, 'data:image/')) {
            return $url;
        }

        // Block dangerous protocols
        foreach ($this->dangerousProtocols as $protocol) {
            if (str_starts_with($lowerUrl, $protocol)) {
                return null;
            }
        }

        // Allow relative URLs, absolute URLs, and protocol-relative URLs
        // (http://, https://, //, /, ./)
        return $url;
    }

    /**
     * Sanitize inline CSS styles
     */
    protected function sanitizeStyle(string $style): string
    {
        $sanitized = [];

        // Parse CSS declarations
        $declarations = explode(';', $style);

        foreach ($declarations as $declaration) {
            $declaration = trim($declaration);
            if (empty($declaration)) {
                continue;
            }

            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $property = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            // Only allow whitelisted properties
            if (! in_array($property, $this->allowedCssProperties)) {
                continue;
            }

            // Block url() and expression() in values
            $lowerValue = strtolower($value);
            if (str_contains($lowerValue, 'url(') ||
                str_contains($lowerValue, 'expression(') ||
                str_contains($lowerValue, 'javascript:')) {
                continue;
            }

            $sanitized[] = $property.': '.$value;
        }

        return implode('; ', $sanitized);
    }

    /**
     * Escape HTML entities for plain text display
     */
    public static function escape(?string $text): string
    {
        if (empty($text)) {
            return '';
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
