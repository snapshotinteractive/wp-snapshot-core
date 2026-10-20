<?php

PHP_SAPI === 'cli' || exit(1);

$root = rtrim($argv[1] ?? '', '/');

if ($root === '' || ! is_dir($root)) {
    fwrite(STDERR, "Usage: php bin/manifest.php <plugin directory>\n");
    exit(1);
}

$files = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (! $file->isFile() || $file->isLink()) {
        continue;
    }

    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

    if ($relative !== 'security-manifest.json') {
        $files[$relative] = hash_file('sha256', $file->getPathname());
    }
}

ksort($files);

file_put_contents("{$root}/security-manifest.json", json_encode([
    'generated_at' => gmdate('c'),
    'commit' => getenv('GITHUB_SHA') ?: null,
    'files' => $files,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

fwrite(STDOUT, sprintf("security-manifest.json: %d files\n", count($files)));
