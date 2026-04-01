<?php

use App\Console\Commands\ImapIdleCommand;
use App\Console\Commands\ImapPullCommand;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        ImapIdleCommand::class,
        ImapPullCommand::class,
    ])
    ->withExceptions()
    ->create();
