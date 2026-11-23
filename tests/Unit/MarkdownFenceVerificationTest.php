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
        $this->expectExceptionMessage('Unclosed Markdown fenced code block in dangling.md starting at line');

        verifyMarkdownFences("Text\n```php\ncode\n", 'dangling.md');
    }

    public function testMismatchedFenceMarkerDoesNotCloseBlock(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unclosed Markdown fenced code block in mismatched.md starting at line');

        verifyMarkdownFences("~~~\n```\n", 'mismatched.md');
    }

    public function testUnclosedFenceInsideBlockquoteIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unclosed Markdown fenced code block in blockquote.md starting at line');

        verifyMarkdownFences("> ```php\n> code\n", 'blockquote.md');
    }

    public function testUnclosedFenceInsideOrderedListIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unclosed Markdown fenced code block in list.md starting at line');

        verifyMarkdownFences("1. ```php\n   code\n", 'list.md');
    }

    public function testUnclosedFenceAfterBlankLineInListItemIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unclosed Markdown fenced code block in nested-list.md starting at line');

        verifyMarkdownFences("1. Example\n\n    ```php\n    echo 'hello';\n", 'nested-list.md');
    }

    public function testClosedFenceAfterBlankLineInListItemIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        verifyMarkdownFences("1. Example\n\n    ```php\n    echo 'hello';\n    ```\n", 'closed-nested-list.md');
    }

    public function testFourSpaceIndentedCodeOutsideAListIsNotTreatedAsFence(): void
    {
        $this->expectNotToPerformAssertions();

        verifyMarkdownFences("    ```php\n    echo 'hello';\n", 'indented-code.md');
    }

    public function testUnclosedFenceInNestedListAfterBlankLineIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unclosed Markdown fenced code block in nested-list.md starting at line');

        verifyMarkdownFences("1. Outer\n\n    1. Inner\n\n       ```php\n       echo 'hello';\n", 'nested-list.md');
    }

    public function testClosedFenceInNestedListAfterBlankLineIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        verifyMarkdownFences("1. Outer\n\n    1. Inner\n\n       ```php\n       echo 'hello';\n       ```\n", 'closed-nested-list.md');
    }

    public function testIndentedCodeAfterListMarkerIsNotTreatedAsFence(): void
    {
        $this->expectNotToPerformAssertions();

        verifyMarkdownFences("1.     ```php\n       echo 'hello';\n", 'list-indented-code.md');
    }

    public function testFenceMarkersInsideHtmlCommentsAreIgnored(): void
    {
        $this->expectNotToPerformAssertions();

        verifyMarkdownFences("<!-- ``` -->\nText\n<!-- comment starts\n```\nstill a comment -->\nMore text\n", 'comment.md');
    }

    public function testListMarkerInsideFenceContentDoesNotCloseTheFence(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unclosed Markdown fenced code block in code-content.md starting at line');

        verifyMarkdownFences("```text\n- ```\n", 'code-content.md');
    }
}
