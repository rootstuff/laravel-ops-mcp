<?php

namespace Rootstuff\OpsMcp\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Rootstuff\OpsMcp\Support\QueueState;

#[IsReadOnly]
#[Name('queue_status')]
#[Title('Queue status')]
#[Description('The queues right now: pending, delayed and reserved jobs per queue and the age of the oldest pending job. With Horizon: every master and supervisor, the processes on each queue, and `starved`, the declared queues no process is on (a queue that looks served while nothing drains it).')]
class QueueStatusTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::json(QueueState::collect());
    }
}
