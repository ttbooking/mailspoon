<?php

use App\Console\Commands\ImapPullCommand;
use App\Console\Commands\ImapSentryCommand;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        ImapPullCommand::class,
        ImapSentryCommand::class,
    ])
    ->withExceptions()
    ->create();
