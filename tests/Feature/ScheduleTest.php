<?php

it('schedules spoon:deliver by default', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('spoon:deliver')
        ->assertSuccessful();
});

it('schedules imap:pull for each configured mailbox', function () {
    config(['mailspoon.schedule.pull' => ['default' => '*/5 * * * *']]);

    $this->artisan('schedule:list')
        ->expectsOutputToContain('imap:pull')
        ->assertSuccessful();
});

it('does not schedule delivery when its cron is empty', function () {
    config(['mailspoon.schedule.deliver' => '']);

    $this->artisan('schedule:list')
        ->doesntExpectOutputToContain('spoon:deliver')
        ->assertSuccessful();
});

it('schedules model pruning with the default three day retention', function () {
    expect(config('mailspoon.retention.days'))->toBe(3);

    $this->artisan('schedule:list')
        ->expectsOutputToContain('model:prune')
        ->assertSuccessful();
});

it('does not schedule model pruning when retention is disabled', function () {
    config(['mailspoon.retention.days' => 0]);

    $this->artisan('schedule:list')
        ->doesntExpectOutputToContain('model:prune')
        ->assertSuccessful();
});
