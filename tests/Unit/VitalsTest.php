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
