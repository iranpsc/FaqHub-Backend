<?php

namespace Tests\Unit\Services;

use App\Services\HtmlSanitizer;
use Tests\TestCase;

class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new HtmlSanitizer;
    }

    public function test_empty_string_returns_empty(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(''));
    }

    public function test_null_returns_empty(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(null));
    }

    public function test_strip_script_tags_completely(): void
    {
        $html = '<p>Hello</p><script>alert("xss")</script><p>World</p>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('World', $result);
    }

    public function test_disallowed_tag_is_unwrapped_but_content_preserved(): void
    {
        $html = '<foo>some text</foo>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsString('<foo>', $result);
        $this->assertStringContainsString('some text', $result);
    }

    public function test_onclick_event_handler_is_removed(): void
    {
        $html = '<p onclick="alert(1)">Click me</p>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringContainsString('Click me', $result);
    }

    public function test_javascript_href_is_blocked(): void
    {
        $html = '<a href="javascript:alert(1)">click</a>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function test_data_image_src_is_allowed_on_img(): void
    {
        $dataUrl = 'data:image/png;base64,iVBORw0KGgo=';
        $html = '<img src="'.$dataUrl.'" alt="test">';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString($dataUrl, $result);
    }

    public function test_data_href_is_blocked_on_anchor(): void
    {
        $html = '<a href="data:text/html,<script>alert(1)</script>">click</a>';
        $result = $this->sanitizer->sanitize($html);

        // data: protocol on href should be blocked
        $this->assertStringNotContainsString('data:text', $result);
    }

    public function test_style_with_url_is_stripped(): void
    {
        $html = '<p style="background: url(http://evil.com/img.png)">text</p>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsString('url(', $result);
        $this->assertStringContainsString('text', $result);
    }

    public function test_safe_style_color_is_kept(): void
    {
        $html = '<p style="color: red">text</p>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('color', $result);
        $this->assertStringContainsString('red', $result);
    }

    public function test_target_blank_adds_rel_noopener(): void
    {
        $html = '<a href="https://example.com" target="_blank">link</a>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('rel="noopener noreferrer"', $result);
    }

    public function test_safe_html_passes_through_intact(): void
    {
        $html = '<p><strong>Bold</strong> and <em>italic</em> text</p>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('<strong>Bold</strong>', $result);
        $this->assertStringContainsString('<em>italic</em>', $result);
    }

    public function test_iframe_is_stripped_completely(): void
    {
        $html = '<iframe src="https://evil.com"></iframe><p>Safe</p>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsString('<iframe', $result);
        $this->assertStringContainsString('Safe', $result);
    }

    public function test_style_tag_is_stripped(): void
    {
        $html = '<style>body { background: red; }</style><p>text</p>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsString('<style', $result);
        $this->assertStringNotContainsString('background: red', $result);
    }

    public function test_style_expression_is_blocked(): void
    {
        $html = '<p style="width: expression(alert(1))">text</p>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsString('expression(', $result);
    }

    public function test_vbscript_href_is_blocked(): void
    {
        $html = '<a href="vbscript:MsgBox(1)">click</a>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsString('vbscript:', $result);
    }

    public function test_allowed_anchor_attributes_pass_through(): void
    {
        $html = '<a href="https://example.com" title="Example" rel="nofollow">link</a>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringContainsString('nofollow', $result);
    }

    public function test_escape_null_returns_empty(): void
    {
        $this->assertSame('', HtmlSanitizer::escape(null));
    }

    public function test_escape_string_encodes_html_entities(): void
    {
        $result = HtmlSanitizer::escape('<script>alert("xss")</script>');

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function test_escape_empty_string_returns_empty(): void
    {
        $this->assertSame('', HtmlSanitizer::escape(''));
    }

    public function test_escape_special_chars(): void
    {
        $result = HtmlSanitizer::escape('"Hello" & \'World\'');

        $this->assertStringContainsString('&amp;', $result);
        $this->assertStringNotContainsString('"Hello"', $result);
    }

    public function test_disallowed_attribute_on_allowed_tag_is_removed(): void
    {
        $html = '<p id="main" data-custom="value">text</p>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsString('data-custom', $result);
        $this->assertStringContainsString('text', $result);
    }

    public function test_empty_href_is_preserved(): void
    {
        $html = '<a href="">empty</a>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('href=""', $result);
    }

    public function test_style_with_empty_and_invalid_declarations_is_cleaned(): void
    {
        $html = '<p style="color: blue;; bogus; background-color: green">text</p>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('color: blue', $result);
        $this->assertStringContainsString('background-color: green', $result);
        $this->assertStringNotContainsString('bogus', $result);
    }
}
