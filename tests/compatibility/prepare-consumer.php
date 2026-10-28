<?php

// Build an independent Laravel application dependency graph; never edit NXS itself.
$repository = $argv[1] ?? '';
$version = $argv[2] ?? '';
if ($repository === '' || $version === '') {
    fwrite(STDERR, "Usage: php tests/compatibility/prepare-consumer.php repository exact-version\n");
    exit(1);
}
$directory = dirname(__DIR__, 2).'/vendor/release-consumer';
if (file_exists($directory.'/composer.lock')) {
    throw new RuntimeException('Existing consumer lock found; use its install or preserve it before preparing a new candidate.');
}
if (!is_dir($directory)) {
    mkdir($directory, 0755, true);
}
$manifest = [
    'name' => 'nxs/neoeloquent-release-consumer',
    'description' => 'Isolated validation consumer; not the production application',
    'license' => 'proprietary',
    'repositories' => [['type' => 'vcs', 'url' => $repository, 'canonical' => true, 'only' => ['ulobby/neoeloquent']]],
    'require' => ['php' => '~8.5.0', 'laravel/framework' => '13.39.0', 'ulobby/neoeloquent' => $version],
    'minimum-stability' => 'stable',
    'prefer-stable' => true,
    'config' => ['allow-plugins' => false],
];
file_put_contents($directory.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "Prepared vendor/release-consumer/composer.json\n";
