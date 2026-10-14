<?php

namespace Rootstuff\OpsMcp\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Rootstuff\OpsMcp\Support\Vitals;

#[IsReadOnly]
#[Name('vitals')]
#[Title('Vitals')]
#[Description('The app and the box it runs on: Laravel and PHP versions, environment, debug flag, the release directory, maintenance mode, config and route caches, opcache; host uptime, load, CPUs, memory, disk under the app and under storage; counts of php-fpm, Horizon, queue:work and nginx processes and the Horizon master\'s age.')]
class VitalsTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::json(Vitals::collect());
    }
}
