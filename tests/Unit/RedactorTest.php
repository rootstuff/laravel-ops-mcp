<?php

use Rootstuff\OpsMcp\Support\Redactor;

it('redacts labelled secrets, bearer tokens and credentials in URLs', function () {
    $line = 'DB_PASSWORD=hunter22 Authorization: Bearer abcdefgh.ijklmnop redis://default:s3cret@10.0.0.1:6379 sk_live_ABCDEFGHIJKLMNOP ghp_abcdefghijklmnopqrstuvwxyz123456';

    $out = Redactor::redact($line);

    expect($out)->not->toContain('hunter22')
        ->not->toContain('ijklmnop')
        ->not->toContain('s3cret')
        ->not->toContain('sk_live_')
        ->not->toContain('ghp_')
        ->toContain('DB_PASSWORD=[redacted]')
        ->toContain('Bearer [redacted]')
        ->toContain('redis://default:[redacted]@10.0.0.1');
});

it('leaves file paths and release hashes alone', function () {
    $line = '#12 /var/www/releases/3f2a9c1b4d5e6f708192a3b4c5d6e7f8091a2b3c/app/Jobs/Thing.php(42)';

    expect(Redactor::redact($line))->toBe($line);
});

it('redacts strings nested in arrays', function () {
    expect(Redactor::redactArray(['a' => ['token=abcd1234'], 'b' => 7]))->toBe(['a' => ['token=[redacted]'], 'b' => 7]);
});
