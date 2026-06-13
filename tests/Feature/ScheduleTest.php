<?php

it('schedules mailspoon:deliver by default', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('mailspoon:deliver')
        ->assertSuccessful();
});

it('schedules mailspoon:pull for each configured mailbox', function () {
    config(['mailspoon.schedule.pull' => ['default' => '*/5 * * * *']]);

    $this->artisan('schedule:list')
        ->expectsOutputToContain('mailspoon:pull')
        ->assertSuccessful();
});

it('does not schedule pull for a mailbox disabled in its route', function () {
    config([
        'mailspoon.schedule.pull' => ['default' => '*/5 * * * *'],
        'mailspoon.routes.default.enabled' => false,
    ]);

    $this->artisan('schedule:list')
        ->doesntExpectOutputToContain('mailspoon:pull')
        ->assertSuccessful();
});

it('does not schedule delivery when its cron is empty', function () {
    config(['mailspoon.schedule.deliver' => '']);

    $this->artisan('schedule:list')
        ->doesntExpectOutputToContain('mailspoon:deliver')
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
