<?php

use Illuminate\Support\Facades\File;
use Rootstuff\OpsMcp\Mcp\OpsServer;
use Rootstuff\OpsMcp\Mcp\Tools\ProcessesTool;
use Rootstuff\OpsMcp\Mcp\Tools\VitalsTool;
use Rootstuff\OpsMcp\Support\Processes;

/**
 * A fake /proc and a fake site: two releases, `current` pointing at the new
 * one, an old SSR pair still alive (its node child holding the port), a new
 * SSR wrapper, a Horizon master, and a stranger from another app.
 *
 * @return array{proc: string, root: string}
 */
function fakeProc(string $base): array
{
    $root = $base.'/site';
    $proc = $base.'/proc';
    File::ensureDirectoryExists($root.'/releases/old');
    File::ensureDirectoryExists($root.'/releases/new');
    File::ensureDirectoryExists($base.'/elsewhere');
    symlink('releases/new', $root.'/current');

    File::ensureDirectoryExists($proc.'/net');
    file_put_contents($proc.'/uptime', "100000.00 50000.00\n");
    // sl local rem st ... inode: one LISTEN (0A) on 0x3592 = 13714 held by inode 777, one ESTABLISHED on another inode
    file_put_contents($proc.'/net/tcp', "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n   0: 0100007F:3592 00000000:0000 0A 00000000:00000000 00:00000000 00000000  1001        0 777 1 0000000000000000 100 0 0 10 0\n   1: 0100007F:0050 0100007F:A1B2 01 00000000:00000000 00:00000000 00000000  1001        0 888 1 0000000000000000 100 0 0 10 0\n");
    file_put_contents($proc.'/net/tcp6', "  sl  local_address                         remote_address                        st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n");

    $add = function (int $pid, string $cwd, array $argv, int $ageSeconds, int $ppid = 1, array $sockets = []) use ($proc) {
        File::ensureDirectoryExists("{$proc}/{$pid}/fd");
        symlink($cwd, "{$proc}/{$pid}/cwd");
        file_put_contents("{$proc}/{$pid}/cmdline", implode("\0", $argv)."\0");
        $startTicks = (100000 - $ageSeconds) * 100;
        file_put_contents("{$proc}/{$pid}/stat", "{$pid} (php artisan) S {$ppid} {$pid} {$pid} 0 -1 4194560 100 0 0 0 5 1 0 0 20 0 1 0 {$startTicks} 100000 1000 18446744073709551615 0 0 0 0 0 0 0 0 0 0 0 0 17 0 0 0 0 0 0\n");
        foreach ($sockets as $fd => $inode) {
            symlink("socket:[{$inode}]", "{$proc}/{$pid}/fd/{$fd}");
        }
    };

    $add(1001, $root.'/releases/old', ['php8.3', 'artisan', 'inertia:start-ssr'], 43200);
    $add(1002, $root.'/releases/old', ['node', $root.'/releases/old/bootstrap/ssr/ssr.js'], 43190, 1001, [5 => 777, 6 => 888]);
    $add(1003, $root.'/releases/new', ['php8.3', 'artisan', 'inertia:start-ssr'], 600);
    $add(1004, $base.'/elsewhere', ['php8.3', 'artisan', 'horizon'], 99999);
    $add(1005, $root.'/releases/new', ['php8.3', '/home/forge/site/current/artisan', 'horizon'], 1000);
    $add(1006, $root.'/releases/new', ['php8.3', 'artisan', 'horizon:work', 'redis', '--name=default', '--token=abcdefgh12345678'], 990, 1005);

    return ['proc' => $proc, 'root' => $root];
}

it('lists this app\'s processes with release, staleness and listening ports, and ignores other apps', function () {
    ['proc' => $proc, 'root' => $root] = fakeProc($this->logDir);

    $listing = Processes::collect($proc, $root);

    expect($listing['scoped'])->toBeTrue()
        ->and($listing['current_release'])->toBe('new')
        ->and($listing['count'])->toBe(5)
        ->and($listing['stale_count'])->toBe(2)
        ->and($listing['by_kind'])->toBe(['horizon-master' => 1, 'horizon-worker' => 1, 'ssr' => 2, 'ssr-node' => 1])
        ->and($listing['by_release'])->toBe(['new' => 3, 'old' => 2]);

    $byPid = collect($listing['processes'])->keyBy('pid');

    expect($byPid->has(1004))->toBeFalse('the other app\'s Horizon must not appear');
    expect($byPid[1002])->toMatchArray(['kind' => 'ssr-node', 'release' => 'old', 'stale' => true, 'listening' => [13714], 'ppid' => 1001, 'age_seconds' => 43190, 'age' => '11h 59m']);
    expect($byPid[1001])->toMatchArray(['kind' => 'ssr', 'release' => 'old', 'stale' => true, 'listening' => []]);
    expect($byPid[1003])->toMatchArray(['kind' => 'ssr', 'release' => 'new', 'stale' => false]);
    expect($byPid[1005])->toMatchArray(['kind' => 'horizon-master', 'age_seconds' => 1000]);
    expect($byPid[1006]['command'])->toContain('--token=[redacted]')->not->toContain('abcdefgh');
    expect($listing['notes'][0])->toContain('2 process(es) run from a release other than current (new)');
});

it('filters by kind and orders the oldest first within a kind', function () {
    ['proc' => $proc, 'root' => $root] = fakeProc($this->logDir);

    $ssr = Processes::collect($proc, $root, 'ssr');

    expect($ssr['count'])->toBe(2)
        ->and(array_column($ssr['processes'], 'pid'))->toBe([1001, 1003]);
});

it('parses stat lines whose comm contains spaces and parentheses', function () {
    $stat = '4242 (php (artisan) x) S 7 4242 4242 0 -1 4194560 1 0 0 0 0 0 0 0 20 0 1 0 9990000 1000 10 18446744073709551615 0 0 0 0 0 0 0 0 0 0 0 0 17 0 0 0 0 0 0';

    expect(Processes::parseStat($stat, 100000.0))->toBe(['ppid' => 7, 'age_seconds' => 100]);
    expect(Processes::parseStat('garbage', 1.0))->toBeNull();
});

it('classifies command lines', function (string $command, string $kind) {
    expect(Processes::kind($command))->toBe($kind);
})->with([
    ['php8.3 /home/forge/app/current/artisan horizon', 'horizon-master'],
    ['php artisan horizon:supervisor host-1 redis', 'horizon-supervisor'],
    ['php artisan horizon:work redis --name=default', 'horizon-worker'],
    ['php artisan queue:work --tries=3', 'queue-worker'],
    ['php artisan inertia:start-ssr', 'ssr'],
    ['node /srv/app/bootstrap/ssr/ssr.mjs', 'ssr-node'],
    ['php artisan schedule:work', 'scheduler'],
    ['php artisan schedule:run', 'scheduler-tick'],
    ['php artisan octane:start --server=swoole', 'octane'],
    ['php artisan reverb:start', 'reverb'],
    ['php artisan mcp:start ops', 'mcp'],
    ['php artisan backup:run', 'artisan'],
    ['node server.js', 'node'],
    ['sh -c sleep 1', 'other'],
]);

it('serves the listing through the tool and feeds the vitals counts', function () {
    OpsServer::tool(ProcessesTool::class)->assertOk()->assertSee('"scoped"')->assertSee('"by_kind"')->assertSee('"processes"');
    OpsServer::tool(ProcessesTool::class, ['kind' => 'horizon-master'])->assertOk()->assertSee('"count"');
    OpsServer::tool(VitalsTool::class)->assertOk()->assertSee('"inertia_ssr"')->assertSee('"horizon_master"');
});

it('explains when there is no /proc to read', function () {
    $listing = Processes::collect($this->logDir.'/no-proc-here', $this->logDir);

    expect($listing['scoped'])->toBeFalse()
        ->and($listing['notes'][0])->toContain('needs /proc');
});
