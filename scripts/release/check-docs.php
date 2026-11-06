<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\ReleaseTools;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/lib.php';

/** @return list<string> */
function anchors(string $markdown): array
{
    preg_match_all('/<a\s+id="([^"]+)"\s*><\/a>/', $markdown, $explicit);
    $anchors = $explicit[1];
    $counts = [];
    $fenced = false;
    foreach (explode("\n", $markdown) as $line) {
        if (str_starts_with($line, '```')) {
            $fenced = !$fenced;
        } elseif (!$fenced && preg_match('/^#{1,6}\s+(.+)$/u', $line, $match) === 1) {
            $title = strip_tags(str_replace(['`', '*'], '', $match[1]));
            $slug = str_replace(' ', '-', preg_replace('/[^\pL\pN _-]/u', '', mb_strtolower($title)) ?? '');
            $index = $counts[$slug] ?? 0;
            $counts[$slug] = $index + 1;
            $anchors[] = $slug . ($index === 0 ? '' : '-' . $index);
        }
    }
    return $anchors;
}

$root = dirname(__DIR__, 2);
$paths = array_merge(glob($root . '/*.md') ?: [], [$root . '/llms.txt']);
foreach (['docs', 'examples'] as $directory) {
    foreach (files($root, $directory) as $path) {
        if (str_ends_with($path, '.md')) {
            $paths[] = $root . '/' . $path;
        }
    }
}
$links = 0;
$snippets = 0;
foreach ($paths as $path) {
    $body = read($path);
    preg_match_all('/!?\[[^\]\n]*\]\(([^\s)]+)(?:\s+"[^"]*")?\)/u', $body, $matches);
    foreach ($matches[1] as $link) {
        if (preg_match('/^[a-zA-Z][\w+.-]*:/', $link) === 1) {
            continue;
        }
        [$destination, $anchor] = array_pad(explode('#', $link, 2), 2, null);
        $target = $destination === '' ? $path : realpath(dirname($path) . '/' . rawurldecode($destination));
        ensure(is_string($target) && file_exists($target), 'Broken local link: ' . $path . ' -> ' . $link);
        ensure(str_starts_with($target, $root . '/'), 'Consumer link escapes package: ' . $link);
        if ($anchor !== null && str_ends_with($target, '.md')) {
            ensure(in_array($anchor, anchors(read($target)), true), 'Broken anchor: ' . $path . ' -> ' . $link);
        }
        ++$links;
    }
    preg_match_all('/^```php\s*\n(.*?)^```/ms', $body, $snippetsFound);
    foreach ($snippetsFound[1] as $snippet) {
        \PhpToken::tokenize(str_starts_with(ltrim($snippet), '<?php') ? $snippet : "<?php\n" . $snippet, TOKEN_PARSE);
        ++$snippets;
    }
}
$reference = read($root . '/docs/reference/PACKAGE_REFERENCE.md');
ensure(str_contains($reference, apiInventory($root)), 'Public API inventory/signatures drifted from the reference.');
$llms = read($root . '/llms.txt');
ensure(preg_match_all('/^# /m', $llms) === 1 && str_starts_with($llms, "# Maatify Email Delivery\n\n> "), 'llms.txt identity/shape is invalid.');
foreach (['README.md', 'docs/reference/PACKAGE_REFERENCE.md', 'docs/guides/USAGE_GUIDE.md', 'examples/README.md', 'CHANGELOG.md', 'SECURITY.md'] as $path) {
    ensure(str_contains($llms, '](' . $path . ')'), 'llms.txt missing consumer navigation: ' . $path);
}
foreach (files($root, 'docs/assets') as $path) {
    if (str_ends_with($path, '.svg')) {
        ensure(simplexml_load_string(read($root . '/' . $path)) !== false, 'Invalid SVG: ' . $path);
    }
}
echo 'PASS: ', count($paths), ' documentation files, ', $links, ' local links, ', $snippets, " PHP snippets; API inventory synchronized.\n";
