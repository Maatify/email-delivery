<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Maatify\EmailDelivery\ReleaseTools\verifyMarkdownFences;

require_once dirname(__DIR__, 2) . '/scripts/release/lib.php';

final class MarkdownFenceVerificationTest extends TestCase
{
    public function testArchitectureDecisionRecordHasBalancedFences(): void
    {
        $path = dirname(__DIR__, 2) . '/docs/ADR-008-Email-Delivery-Independent-Queue.md';
        $markdown = file_get_contents($path);
        self::assertIsString($markdown);

        verifyMarkdownFences($markdown, 'docs/ADR-008-Email-Delivery-Independent-Queue.md');

        self::assertSame(2, preg_match_all('/^```/m', $markdown));
    }

    public function testBalancedBacktickAndTildeFencesAreAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        verifyMarkdownFences("````php\n<?php\n```\n````\n\n~~~yaml\nkey: value\n~~~~\n> ```php\n> code\n> ```\n1. ~~~text\n   code\n   ~~~\n", 'balanced.md');
    }

    public function testUnclosedFenceIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unclosed Markdown fenced code block in dangling.md.');

        verifyMarkdownFences("Text\n```php\ncode\n", 'dangling.md');
    }

    public function testMismatchedFenceMarkerDoesNotCloseBlock(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unclosed Markdown fenced code block in mismatched.md.');

        verifyMarkdownFences("~~~\n```\n", 'mismatched.md');
    }

    public function testUnclosedFenceInsideBlockquoteIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unclosed Markdown fenced code block in blockquote.md.');

        verifyMarkdownFences("> ```php\n> code\n", 'blockquote.md');
    }

    public function testUnclosedFenceInsideOrderedListIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unclosed Markdown fenced code block in list.md.');

        verifyMarkdownFences("1. ```php\n   code\n", 'list.md');
    }

    public function testFenceMarkersInsideHtmlCommentsAreIgnored(): void
    {
        $this->expectNotToPerformAssertions();

        verifyMarkdownFences("<!-- ``` -->\nText\n<!-- comment starts\n```\nstill a comment -->\nMore text\n", 'comment.md');
    }

    public function testListMarkerInsideFenceContentDoesNotCloseTheFence(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unclosed Markdown fenced code block in code-content.md.');

        verifyMarkdownFences("```text\n- ```\n", 'code-content.md');
    }
}
