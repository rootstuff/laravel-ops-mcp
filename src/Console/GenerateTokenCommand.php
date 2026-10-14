<?php

namespace Rootstuff\OpsMcp\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateTokenCommand extends Command
{
    protected $signature = 'ops-mcp:token';

    protected $description = 'Generate a bearer token for the ops MCP web endpoint';

    public function handle(): int
    {
        $token = Str::random(64);

        $this->line('Add this to your .env, then set OPS_MCP_ENABLED=true:');
        $this->newLine();
        $this->line("OPS_MCP_TOKEN={$token}");
        $this->newLine();
        $this->line('Connect an agent with: Authorization: Bearer <that token> at '.rtrim((string) config('app.url'), '/').config('ops-mcp.path', '/mcp/ops'));

        return self::SUCCESS;
    }
}
