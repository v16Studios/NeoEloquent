<?php

// Generate an isolated Composer project without changing the library manifest.
$major = $argv[1] ?? '';
if (!in_array($major, ['12', '13'], true)) {
    fwrite(STDERR, "Usage: php tests/compatibility/prepare-dependencies.php 12|13 [--locked]\n");
    exit(1);
}
$root = dirname(__DIR__, 2);
$directory = $root.'/vendor/laravel'.$major;
if (!is_dir($directory)) {
    mkdir($directory, 0755, true);
}
if (($argv[2] ?? '') === '--locked') {
    $snapshot = __DIR__.'/locks/laravel'.$major;
    foreach (['composer.json', 'composer.lock'] as $file) {
        if (!is_file($snapshot.'/'.$file)) {
            throw new RuntimeException('Missing release dependency snapshot: '.$file);
        }
        copy($snapshot.'/'.$file, $directory.'/'.$file);
    }
    echo 'Restored locked vendor/laravel'.$major." dependencies; run composer install there.\n";
    exit(0);
}
$manifest = json_decode(file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($manifest['require'] as $package => &$constraint) {
    if (str_starts_with($package, 'illuminate/')) {
        $allowed = array_filter(explode(' || ', $constraint), fn ($version) => str_starts_with($version, '^'.$major.'.'));
        $constraint = implode(' || ', $allowed);
        if ($constraint === '') {
            throw new RuntimeException('The library does not support Laravel '.$major);
        }
    }
}
unset($constraint);
$manifest['autoload']['psr-4']['Vinelab\\NeoEloquent\\'] = '../../src';
$manifest['autoload-dev']['classmap'] = ['../../tests/TestCase.php'];
file_put_contents($directory.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo 'Generated vendor/laravel'.$major."/composer.json\n";
