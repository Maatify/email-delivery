<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\ReleaseTools;

require_once __DIR__ . '/lib.php';
try {
    $directory = evidenceDirectory('email-artifact');
    $root = dirname(__DIR__, 2);
    $targetMetadata = jsonFile($root . '/release-target.json');
    $target = $targetMetadata['target'] ?? null;
    ensure(is_string($target), 'Exact release target is required.');
    $allowDirty = in_array('--allow-dirty', $argv ?? [], true);
    $dirty = trim(run(['git', 'status', '--porcelain'], $root)) !== '';
    ensure(!$dirty || $allowDirty, 'Commit the candidate first, or explicitly use --allow-dirty for a non-qualifying rehearsal.');
    verifyIdentity($root, $target);
    $sha = trim(run(['git', 'rev-parse', 'HEAD'], $root));
    if (($targetMetadata['publication_state_current'] ?? 'unpublished') === 'published') {
        require_once __DIR__ . '/verify-published-snapshot.php';
        verifyPublishedSnapshot($root, $directory, $target, $sha, $dirty);
        exit(0);
    }
    $environment = controlledEnvironment($directory);
    $required = requiredFiles($root);
    $composerOutput = run(['composer', 'archive', '--format=zip', '--dir=' . $directory, '--file=composer-candidate', '--no-plugins', '--no-scripts', '--no-interaction'], $root, $environment);
    file_put_contents($directory . '/composer-archive.log', $composerOutput);
    $composer = verifyZip($directory . '/composer-candidate.zip', $root, $required);
    $git = null;
    if (!$dirty) {
        run(['git', 'archive', '--format=zip', '--output=' . $directory . '/git-candidate.zip', 'HEAD'], $root);
        $git = verifyZip($directory . '/git-candidate.zip', $root, $required);
        ensure($git === $composer, 'Git export and Composer archive differ.');
        $tracked = explode("\0", trim(run(['git', 'ls-files', '-z'], $root), "\0"));
        foreach (array_keys($composer) as $file) {
            ensure(in_array($file, $tracked, true), 'Archive contains untracked content: ' . $file);
        }
    }
    $consumer = $directory . '/archive-consumer';
    ensure(mkdir($consumer, 0700), 'Cannot create archive consumer.');
    $zip = new \ZipArchive();
    ensure($zip->open($directory . '/composer-candidate.zip') === true, 'Cannot reopen verified archive.');
    ensure($zip->extractTo($consumer), 'Cannot extract verified archive.');
    $zip->close();
    $installLog = run(['composer', 'install', '--no-dev', '--no-plugins', '--no-scripts', '--no-interaction', '--no-progress', '--prefer-dist'], $consumer, $environment);
    file_put_contents($directory . '/archive-production-install.log', $installLog);
    $types = [];
    foreach ($required as $path) {
        if (str_starts_with($path, 'src/') && str_ends_with($path, '.php') && !str_contains($path, '/Internal/')) {
            $types[] = 'Maatify\\EmailDelivery\\' . str_replace('/', '\\', substr($path, 4, -4));
        }
    }
    file_put_contents($directory . '/public-types.json', json_encode($types, JSON_THROW_ON_ERROR));
    $smoke = 'require "vendor/autoload.php"; foreach (json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR) as $type) { if (!class_exists($type) && !interface_exists($type) && !enum_exists($type)) {throw new RuntimeException("Missing production type: ".$type);} } echo "All public types autoload from the archive production installation.\n";';
    file_put_contents($directory . '/archive-smoke.log', run([PHP_BINARY, '-r', $smoke, $directory . '/public-types.json'], $consumer, $environment));
    $report = ['gate' => 'Release Artifact Verification', 'package' => 'maatify/email-delivery', 'target' => $target,
        'candidate_sha' => $sha, 'candidate_tree' => trim(run(['git', 'rev-parse', 'HEAD^{tree}'], $root)),
        'mode' => $dirty ? 'dirty-worktree-rehearsal' : 'committed-candidate',
        'outcome' => 'PASS', 'required_files' => count($required), 'files' => $composer,
        'composer_archive_sha256' => hash_file('sha256', $directory . '/composer-candidate.zip'),
        'git_archive_verified' => $git !== null, 'archive_production_autoload' => 'PASS', 'composer_version' => trim(run(['composer', '--version', '--no-ansi'], $root, $environment)),
        'semantic_review_reference' => 'docs/releases/1.2.0-review.md',
        'qualification_boundary' => 'Owner-reviewed integration to main; Full Applicable CI and this gate must pass on that same SHA.',
        'publication' => 'Not performed; no tag/release authorization is inferred.'];
    file_put_contents($directory . '/artifact-verification.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    echo 'PASS: ', $target, ' / ', $sha, ' / ', $report['mode'], '. Evidence: ', $directory, "\n";
} catch (\Throwable $failure) {
    $failureReport = ['outcome' => 'FAIL', 'target' => $target ?? $version ?? null,
        'candidate_sha' => $sha ?? null, 'error' => $failure->getMessage()];
    if (isset($directory)) {
        file_put_contents($directory . '/failure.json', json_encode($failureReport, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    }
    fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . "\n");
    exit(1);
}
