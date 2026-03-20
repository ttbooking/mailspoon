<?php

use App\Console\Commands\ImapIdleCommand;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([ImapIdleCommand::class])
    ->withExceptions()
    ->create();
