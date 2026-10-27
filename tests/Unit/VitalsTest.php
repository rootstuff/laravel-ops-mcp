<?php

use Rootstuff\OpsMcp\Support\Vitals;

it('parses ps etime values on every platform', function (string $etime, ?int $seconds) {
    expect(Vitals::parseElapsed($etime))->toBe($seconds);
})->with([
    ['00:05', 5],
    ['12:34', 754],
    ['01:02:03', 3723],
    ['3-01:02:03', 262923],
    ['', null],
    ['garbage', null],
    ['90', null],
]);

it('formats a duration in days, hours and minutes', function () {
    expect(Vitals::duration(59))->toBe('0m')
        ->and(Vitals::duration(3600))->toBe('1h 0m')
        ->and(Vitals::duration(90061))->toBe('1d 1h 1m');
});

it('collapses release and current layouts to the site root for process scoping', function (string $basePath, string $root) {
    expect(Vitals::appRoot($basePath))->toBe($root);
})->with([
    ['/home/forge/rootstuff.io/releases/768657b8847747e1e1cc0ad8a65ff07d1e1e0890', '/home/forge/rootstuff.io'],
    ['/home/forge/rootstuff.io/current/', '/home/forge/rootstuff.io'],
    ['/home/forge/plain-site', '/home/forge/plain-site'],
    ['/var/www/app/releases/20261027020000', '/var/www/app'],
]);
