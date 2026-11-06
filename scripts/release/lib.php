<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\ReleaseTools;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/** @phpstan-assert true $condition */
function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param list<string> $command
 * @param array<string, string>|null $environment
 */
function run(array $command, string $cwd, ?array $environment = null): string
{
    $log = tmpfile();
    ensure($log !== false, 'Cannot open an owned process log.');
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, $cwd, $environment);
    ensure(is_resource($process), 'Cannot start verification process.');
    $code = proc_close($process);
    rewind($log);
    $output = stream_get_contents($log);
    fclose($log);
    ensure(is_string($output), 'Cannot read verification output.');
    ensure($code === 0, 'Command failed: ' . implode(' ', $command) . "\n" . $output);
    return $output;
}

/** @return list<string> */
function files(string $root, string $directory): array
{
    ensure(is_dir($root . '/' . $directory), 'Missing directory: ' . $directory);
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        /** @var \SplFileInfo $file */
        if ($file->isFile()) {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    sort($files);
    return $files;
}

/** @return array<string, mixed> */
function jsonFile(string $path): array
{
    $raw = file_get_contents($path);
    ensure(is_string($raw), 'Cannot read JSON: ' . $path);
    $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    ensure(is_array($value) && !array_is_list($value), 'Expected JSON object: ' . $path);
    /** @var array<string, mixed> $value */
    return $value;
}

function read(string $path): string
{
    $value = file_get_contents($path);
    ensure(is_string($value), 'Missing readable file: ' . $path);
    return $value;
}

/** @return list<string> */
function requiredFiles(string $root): array
{
    $paths = ['README.md', 'CHANGELOG.md', 'SECURITY.md', 'SECURITY_AUDIT.md', 'CONTRIBUTING.md', 'CODE_OF_CONDUCT.md', 'LICENSE', 'VERSION', 'composer.json', 'llms.txt', 'release-target.json'];
    foreach (['src', 'docs', 'examples', 'schema'] as $directory) {
        $paths = array_merge($paths, files($root, $directory));
    }
    sort($paths);
    return $paths;
}

/** Canonical generated signature block; it remains documentation, not a separate API authority. */
function apiInventory(string $root): string
{
    $output = "<!-- BEGIN PUBLIC API INVENTORY -->\n";
    foreach (files($root, 'src') as $path) {
        if (!str_ends_with($path, '.php') || str_contains($path, '/Internal/')) {
            continue;
        }
        $name = 'Maatify\\EmailDelivery\\' . str_replace('/', '\\', substr($path, 4, -4));
        ensure(class_exists($name) || interface_exists($name) || enum_exists($name), 'Public type does not autoload: ' . $name);
        $class = new ReflectionClass($name);
        $output .= "\n### `" . $name . "`\n\n```text\n";
        $kind = $class->isEnum() ? 'enum' : ($class->isInterface() ? 'interface' : 'class');
        $output .= ($class->isFinal() && !$class->isEnum() ? 'final ' : '') . ($class->isReadOnly() ? 'readonly ' : '') . $kind . ' ' . $class->getShortName();
        if ($class->isEnum()) {
            ensure(enum_exists($name), 'Expected enum type.');
            $output .= ': ' . (string) (new ReflectionEnum($name))->getBackingType();
        }
        $parent = $class->getParentClass();
        if ($parent !== false) {
            $output .= ' extends \\' . $parent->getName();
        }
        if (!$class->isInterface() && !$class->isEnum() && $class->getInterfaceNames() !== []) {
            $output .= ' implements \\' . implode(', \\', $class->getInterfaceNames());
        }
        $output .= "\n";
        if ($class->isEnum()) {
            ensure(enum_exists($name), 'Expected backed enum.');
            $enum = new ReflectionEnum($name);
            foreach ($enum->getCases() as $case) {
                $output .= 'case ' . $case->getName();
                if ($case instanceof \ReflectionEnumBackedCase) {
                    $output .= ' = ' . var_export($case->getBackingValue(), true);
                }
                $output .= ";\n";
            }
        }
        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() === $name && !$class->isEnum()) {
                $output .= 'public ' . ($property->isReadOnly() ? 'readonly ' : '') . (string) $property->getType() . ' $' . $property->getName() . ";\n";
            }
        }
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $name || ($class->isEnum() && in_array($method->getName(), ['cases', 'from', 'tryFrom'], true))) {
                continue;
            }
            $output .= 'public ' . ($method->isStatic() ? 'static ' : '') . 'function ' . $method->getName() . "(\n";
            foreach ($method->getParameters() as $parameter) {
                $output .= '    ' . (string) $parameter->getType() . ' $' . $parameter->getName();
                if ($parameter->isDefaultValueAvailable()) {
                    $default = $parameter->getDefaultValue();
                    $output .= ' = ' . ($default === [] ? '[]' : var_export($default, true));
                }
                $output .= ",\n";
            }
            $output .= ')' . ($method->hasReturnType() ? ': ' . (string) $method->getReturnType() : '') . ";\n";
        }
        $output .= "```\n";
    }
    return $output . "\n<!-- END PUBLIC API INVENTORY -->";
}

/** @return array<string, string> */
function controlledEnvironment(string $directory): array
{
    $path = getenv('PATH');
    ensure(is_string($path), 'PATH is required to invoke Composer/Git.');
    return ['PATH' => $path, 'HOME' => $directory, 'COMPOSER_HOME' => $directory . '/composer-home',
        'COMPOSER_CACHE_DIR' => $directory . '/composer-cache', 'COMPOSER_NO_INTERACTION' => '1'];
}

function evidenceDirectory(string $prefix): string
{
    $selected = getenv('EMAIL_RELEASE_EVIDENCE_DIR');
    $path = $selected === false ? sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6)) : $selected;
    ensure(str_starts_with($path, '/'), 'Evidence directory must be absolute.');
    ensure(!file_exists($path) || (is_dir($path) && !is_link($path) && count(scandir($path) ?: []) === 2), 'Evidence directory must be new or empty.');
    ensure(is_dir($path) || mkdir($path, 0700, true), 'Cannot create evidence directory.');
    $resolved = realpath($path);
    $repository = realpath(dirname(__DIR__, 2));
    ensure(is_string($resolved) && is_string($repository) && $resolved !== $repository && !str_starts_with($resolved, $repository . '/'), 'Evidence must be outside the Git checkout.');
    return $resolved;
}

/** Mechanical identity checks supplement, and never replace, semantic review. */
function verifyIdentity(string $root, string $version): void
{
    ensure(preg_match('/\A\d+\.\d+\.\d+\z/', $version) === 1, 'Expected an exact Stable SemVer target.');
    $manifest = jsonFile($root . '/composer.json');
    $target = jsonFile($root . '/release-target.json');
    ensure(($manifest['name'] ?? null) === 'maatify/email-delivery' && ($manifest['type'] ?? null) === 'library', 'Wrong Composer package identity.');
    ensure(!array_key_exists('version', $manifest), 'Composer version must be derived from the tag, not hardcoded.');
    ensure(($target['package'] ?? null) === $manifest['name'] && ($target['target'] ?? null) === $version, 'Wrong release target identity.');
    ensure(trim(read($root . '/VERSION')) === $version, 'VERSION differs from the exact target.');
    $readme = read($root . '/README.md');
    ensure(str_contains($readme, 'Release artifact target: **' . $version . '**'), 'README lacks exact artifact identity.');
    ensure(str_contains($readme, 'composer require maatify/email-delivery:' . $version), 'README lacks exact installation target.');
    ensure(str_contains($readme, 'unpublished') && str_contains($readme, 'only after'), 'Missing pre-publication installation boundary.');
    ensure(str_contains($readme, '## Public Runtime API'), 'Missing README API overview.');
    $changelog = read($root . '/CHANGELOG.md');
    ensure(str_contains($changelog, '## [' . $version . "]\n"), 'Missing undated exact-target changelog section.');
    ensure(!str_contains($changelog, 'releases/tag/v' . $version), 'Unpublished target must not link to a nonexistent release.');
    $parts = explode('## [' . $version . ']', $changelog, 2);
    ensure(preg_match('/^### /m', $parts[0]) !== 1, 'Allocated changes remain under Unreleased.');
    $security = read($root . '/SECURITY.md');
    ensure(str_contains($security, '**' . $version . '**') && str_contains($security, '| 1.x'), 'Security identity/support is inconsistent.');
    ensure(str_contains($security, 'unpublished') && str_contains($security, 'security@maatify.dev'), 'Security lifecycle/reporting mismatch.');
    foreach (['docs/guides/USAGE_GUIDE.md', 'docs/reference/PACKAGE_REFERENCE.md', 'examples/README.md', 'llms.txt', 'CONTRIBUTING.md', 'CODE_OF_CONDUCT.md', 'LICENSE'] as $file) {
        ensure(is_file($root . '/' . $file), 'Required consumer file missing: ' . $file);
    }
}

/**
 * @param list<string> $required
 * @return array<string, string>
 */
function verifyZip(string $zipPath, string $sourceRoot, array $required): array
{
    $zip = new \ZipArchive();
    ensure($zip->open($zipPath) === true, 'Cannot inspect archive: ' . $zipPath);
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; ++$i) {
        $path = $zip->getNameIndex($i);
        ensure(is_string($path), 'Archive entry name is missing.');
        ensure(!str_starts_with($path, '/') && !str_contains($path, '\\') && !in_array('..', explode('/', $path), true), 'Unsafe archive path.');
        if (str_ends_with($path, '/')) {
            continue;
        }
        $operatingSystem = 0;
        $attributes = 0;
        ensure($zip->getExternalAttributesIndex($i, $operatingSystem, $attributes), 'Cannot inspect archive entry type.');
        ensure((($attributes >> 16) & 0170000) !== 0120000, 'Archive symlinks are not permitted.');
        ensure(preg_match('~(^|/)(\.git|\.env[^/]*|vendor|tests|scripts|\.github|\.idea|\.vscode|llm|coverage|logs|var)(/|$)|(^|/)(composer\.lock|\.DS_Store|Thumbs\.db|[^/]*\.log|[^/]*\.patch|phpunit\.xml|phpstan\.neon)$~', $path) !== 1, 'Prohibited archive content: ' . $path);
        ensure(!array_key_exists($path, $entries), 'Duplicate archive entry: ' . $path);
        $bytes = $zip->getFromIndex($i);
        ensure(is_string($bytes), 'Cannot read archive entry: ' . $path);
        ensure(is_file($sourceRoot . '/' . $path) && !is_link($sourceRoot . '/' . $path), 'Archive entry has no regular source file: ' . $path);
        ensure(hash_equals(hash('sha256', read($sourceRoot . '/' . $path)), hash('sha256', $bytes)), 'Archive bytes differ from source: ' . $path);
        $entries[$path] = hash('sha256', $bytes);
    }
    foreach ($required as $path) {
        ensure(isset($entries[$path]), 'Required archive file omitted: ' . $path);
    }
    $zip->close();
    ksort($entries);
    return $entries;
}


/**
 * @param array<string, mixed> $installed
 * @return array<string, mixed>
 */
function verifyInstalledMetadata(array $installed, string $version, string $sha): array
{
    $packages = $installed['packages'] ?? null;
    ensure(is_array($packages), 'Installed package metadata is missing.');
    $resolved = null;
    foreach ($packages as $package) {
        if (is_array($package) && ($package['name'] ?? null) === 'maatify/email-delivery') {
            ensure($resolved === null, 'Duplicate installed package metadata.');
            $resolved = $package;
        }
    }
    ensure(is_array($resolved) && is_string($resolved['version'] ?? null) && in_array($resolved['version'], [$version, 'v' . $version], true), 'Wrong resolved exact version.');
    ensure(($resolved['installation-source'] ?? null) === 'dist', 'Expected actual dist installation; source fallback is NOT PASS.');
    $dist = $resolved['dist'] ?? null;
    $source = $resolved['source'] ?? null;
    ensure(is_array($dist) && ($dist['reference'] ?? null) === $sha && ($dist['type'] ?? null) === 'zip' && is_string($dist['url'] ?? null), 'Dist does not identify the qualified SHA.');
    ensure(is_array($source) && ($source['reference'] ?? null) === $sha, 'Source reference differs from qualified SHA.');
    /** @var array<string, mixed> $resolved */
    return $resolved;
}
