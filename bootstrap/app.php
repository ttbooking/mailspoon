<?php

use Illuminate\Foundation\Application;
use Webklex\IMAP\Commands\ImapIdleCommand;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([ImapIdleCommand::class])
    ->withExceptions()
    ->create();
