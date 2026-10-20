<?php

PHP_SAPI === 'cli' || exit(1);

[, $plugin, $version, $notesFile, $tested, $updates, $repository] = array_pad($argv, 7, '');

if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    fwrite(STDOUT, "latest=false\n");
    fwrite(STDERR, "{$version} is a pre-release, so no update channel points at it.\n");
    exit(0);
}

$source = (string) file_get_contents($plugin);
$header = fn (string $name) => preg_match('/^ \* '.preg_quote($name, '/').':\s+(.+)$/m', $source, $m) ? trim($m[1]) : '';

$release = [
    'version' => $version,
    'package' => "https://github.com/{$repository}/releases/download/v{$version}/snapshot-core.zip",
    'url' => "https://github.com/{$repository}/releases/tag/v{$version}",
    'requires' => $header('Requires at least'),
    'requires_php' => $header('Requires PHP'),
    'tested' => $tested,
    'released' => gmdate('Y-m-d'),
    'notes' => trim((string) file_get_contents($notesFile)),
];

is_dir($updates) || mkdir($updates, 0755, true);

$latest = false;

foreach ([(string) (int) $version, 'latest'] as $channel) {
    $path = "{$updates}/{$channel}.json";
    $current = is_file($path) ? (json_decode((string) file_get_contents($path), true)['version'] ?? '0.0.0') : '0.0.0';

    if (version_compare($version, $current, '<')) {
        fwrite(STDERR, "{$channel}.json stays on {$current}.\n");

        continue;
    }

    file_put_contents($path, json_encode($release, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    fwrite(STDERR, "{$channel}.json now points at {$version}.\n");
    $latest = $latest || $channel === 'latest';
}

fwrite(STDOUT, 'latest='.($latest ? 'true' : 'false')."\n");
