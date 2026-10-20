<?php

PHP_SAPI === 'cli' || exit(1);

$root = rtrim($argv[1] ?? '', '/');
$expected = $argv[2] ?? '';
$failures = [];

foreach (['snapshot-core.php', 'LICENSE', 'vendor-prefixed/autoload.php', 'build/manifest.json', 'security-manifest.json', 'resources/views', 'resources/css/tokens.css'] as $path) {
    if (! file_exists("{$root}/{$path}")) {
        $failures[] = "missing {$path}";
    }
}

foreach (['vendor', 'node_modules', 'bin', 'dist', '.cache'] as $path) {
    if (file_exists("{$root}/{$path}")) {
        $failures[] = "{$path} must not ship";
    }
}

$source = (string) @file_get_contents("{$root}/snapshot-core.php");
preg_match('/^ \* Version:\s+(\S+)/m', $source, $header);
preg_match("/define\('SNAPSHOT_CORE_VERSION', '([^']+)'\);/", $source, $constant);

if (($header[1] ?? null) !== $expected || ($constant[1] ?? null) !== $expected) {
    $failures[] = sprintf('version header %s and constant %s, expected %s', $header[1] ?? 'none', $constant[1] ?? 'none', $expected);
}

if (is_file("{$root}/vendor-prefixed/autoload.php")) {
    require "{$root}/vendor-prefixed/autoload.php";

    foreach (['Snapshot\\Vendor\\OTPHP\\TOTP', 'Snapshot\\Vendor\\BaconQrCode\\Writer', 'Snapshot\\Vendor\\ZxcvbnPhp\\Zxcvbn', 'Snapshot\\Vendor\\lbuchs\\WebAuthn\\WebAuthn', 'Snapshot\\Vendor\\Psr\\Clock\\ClockInterface'] as $class) {
        if (! class_exists($class) && ! interface_exists($class)) {
            $failures[] = "{$class} does not autoload";
        }
    }
}

$manifest = json_decode((string) @file_get_contents("{$root}/build/manifest.json"), true);

foreach (['resources/css/login.css', 'resources/css/security-admin.css', 'resources/css/security-adminbar.css', 'resources/css/snap-admin.css', 'resources/css/seo-admin.css', 'resources/js/security.js', 'resources/js/seo-admin.js'] as $entry) {
    if (! is_file("{$root}/build/".($manifest[$entry]['file'] ?? 'missing'))) {
        $failures[] = "build entry {$entry} is missing";
    }
}

$unprefixed = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/src", FilesystemIterator::SKIP_DOTS)) as $file) {
    if (preg_match('/^use (OTPHP|BaconQrCode|ZxcvbnPhp|lbuchs|ParagonIE|DASPRiD|Psr\\\\Clock)\\\\/m', (string) file_get_contents($file->getPathname()))) {
        $unprefixed[] = substr($file->getPathname(), strlen($root) + 1);
    }
}

foreach ($unprefixed as $path) {
    $failures[] = "{$path} imports a bundled library without the Snapshot\\Vendor prefix";
}

if ($failures !== []) {
    fwrite(STDERR, "snapshot-core package check failed:\n  - ".implode("\n  - ", $failures)."\n");
    exit(1);
}

fwrite(STDOUT, "snapshot-core {$expected}: package is complete\n");
