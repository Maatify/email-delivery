<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\ReleaseTools;

require_once __DIR__ . '/lib.php';
try {
    $arguments = getopt('', ['version:', 'sha:']);
    $version = $arguments['version'] ?? null;
    $sha = $arguments['sha'] ?? null;
    ensure(is_string($version) && preg_match('/\A\d+\.\d+\.\d+\z/', $version) === 1, 'Provide --version=<exact Stable version>.');
    ensure(is_string($sha) && preg_match('/\A[a-f0-9]{40}\z/', $sha) === 1, 'Provide --sha=<release-qualified commit>.');
    $root = dirname(__DIR__, 2);
    ensure(trim(run(['git', 'cat-file', '-t', $sha], $root)) === 'commit', 'Expected qualified commit object.');
    $expectedTarget = json_decode(run(['git', 'show', $sha . ':release-target.json'], $root), true, 512, JSON_THROW_ON_ERROR);
    ensure(is_array($expectedTarget) && ($expectedTarget['target'] ?? null) === $version, 'Qualified target does not match version.');
    $directory = evidenceDirectory('email-published');
    $project = $directory . '/external-consumer';
    ensure(mkdir($project, 0700), 'Cannot create fresh external Composer root.');
    $environment = controlledEnvironment($directory);
    $manifest = ['name' => 'verification/external-email-consumer', 'require' => ['maatify/email-delivery' => $version],
        'config' => ['allow-plugins' => false, 'preferred-install' => 'dist'], 'minimum-stability' => 'stable'];
    file_put_contents($project . '/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    $composerVersion = trim(run(['composer', '--version', '--no-ansi'], $project, $environment));
    file_put_contents($directory . '/effective-config.txt', run(['composer', 'config', '--list', '--source'], $project, $environment));
    // No custom repository, path package, inherited Composer auth/config/cache or source-only fallback acceptance.
    $log = run(['composer', 'install', '--prefer-dist', '--no-dev', '--no-plugins', '--no-scripts', '--no-interaction', '--no-progress'], $project, $environment);
    file_put_contents($directory . '/composer-install.log', $log);
    $installed = jsonFile($project . '/vendor/composer/installed.json');
    $resolved = verifyInstalledMetadata($installed, $version, $sha);
    $dist = $resolved['dist'];
    $source = $resolved['source'];
    $installedPath = $project . '/vendor/maatify/email-delivery';
    verifyIdentity($installedPath, $version);
    $export = $directory . '/qualified-export.zip';
    run(['git', 'archive', '--format=zip', '--output=' . $export, $sha], $root);
    $zip = new \ZipArchive();
    ensure($zip->open($export) === true, 'Cannot inspect independent qualified export.');
    $files = [];
    for ($index = 0; $index < $zip->numFiles; ++$index) {
        $path = $zip->getNameIndex($index);
        ensure(is_string($path), 'Qualified archive entry missing.');
        if (str_ends_with($path, '/')) {
            continue;
        }
        $bytes = $zip->getFromIndex($index);
        ensure(is_string($bytes), 'Qualified archive bytes missing.');
        ensure(!is_link($installedPath . '/' . $path), 'Installed package contains a symlink: ' . $path);
        $actual = read($installedPath . '/' . $path);
        ensure(hash_equals(hash('sha256', $bytes), hash('sha256', $actual)), 'Installed bytes differ from qualified export: ' . $path);
        $files[$path] = hash('sha256', $actual);
    }
    $zip->close();
    $actualFiles = [];
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($installedPath, \FilesystemIterator::SKIP_DOTS)) as $file) {
        /** @var \SplFileInfo $file */
        ensure(!$file->isLink(), 'Installed package contains symlinks.');
        if ($file->isFile()) {
            $actualFiles[] = substr($file->getPathname(), strlen($installedPath) + 1);
        }
    }
    $expectedFiles = array_keys($files);
    sort($actualFiles);
    sort($expectedFiles);
    ensure($actualFiles === $expectedFiles, 'Installed dist has unexpected or missing files.');
    $report = ['gate' => 'Published Artifact Verification', 'outcome' => 'PASS', 'package' => 'maatify/email-delivery',
        'version' => $version, 'qualified_sha' => $sha, 'composer_version' => $composerVersion,
        'observed_installation_mode' => $resolved['installation-source'], 'dist' => $dist, 'source' => $source,
        'installed_path' => $installedPath, 'files' => $files,
        'boundary' => 'Integrity evidence only. External availability and live publication-state freshness remain separate determinations.'];
    file_put_contents($directory . '/published-verification.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    echo 'PASS: externally installed ', $version, ' as dist, matching ', $sha, '. Evidence: ', $directory, "\n";
} catch (\Throwable $failure) {
    $failureReport = ['outcome' => 'FAIL', 'target' => $target ?? $version ?? null,
        'candidate_sha' => $sha ?? null, 'error' => $failure->getMessage()];
    if (isset($directory)) {
        file_put_contents($directory . '/failure.json', json_encode($failureReport, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    }
    fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . "\n");
    exit(1);
}
