<?php

it('schedules spoon:deliver by default', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('spoon:deliver')
        ->assertSuccessful();
});

it('schedules imap:pull for each configured mailbox', function () {
    config(['spoon.schedule.pull' => ['default' => '*/5 * * * *']]);

    $this->artisan('schedule:list')
        ->expectsOutputToContain('imap:pull')
        ->assertSuccessful();
});

it('does not schedule delivery when its cron is empty', function () {
    config(['spoon.schedule.deliver' => '']);

    $this->artisan('schedule:list')
        ->doesntExpectOutputToContain('spoon:deliver')
        ->assertSuccessful();
});
