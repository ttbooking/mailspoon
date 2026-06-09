<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('relayed_messages', function (Blueprint $table) {
            $table->id();

            // Idempotency key: the Message-Id header, or a hash of the raw
            // message when the header is absent. Guards against re-capturing
            // and re-delivering the same email.
            $table->string('fingerprint')->unique();
            $table->string('message_id')->nullable()->index();

            // Where the message came from.
            $table->string('mailbox')->nullable();
            $table->string('folder')->nullable();

            // Where it is being relayed to.
            $table->string('endpoint');

            // Delivery state machine: pending -> delivered | failed.
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('last_error')->nullable();

            // When a failed message becomes eligible for the next delivery
            // attempt (back-off between `spoon:deliver` runs).
            $table->timestamp('next_attempt_at')->nullable()->index();

            // Location of the archived raw MIME on the storage disk.
            $table->string('archive_path')->nullable();

            $table->timestamp('received_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('relayed_messages');
    }
};
