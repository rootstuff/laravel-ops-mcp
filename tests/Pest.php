<?php

use Illuminate\Support\Facades\File;
use Rootstuff\OpsMcp\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

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
