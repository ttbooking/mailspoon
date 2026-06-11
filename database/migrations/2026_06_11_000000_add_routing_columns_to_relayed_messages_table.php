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
        Schema::table('relayed_messages', function (Blueprint $table) {
            // Named delivery target within a mailbox route. Always 'default'
            // for now; fan-out (one mailbox, several targets) will add more
            // values without another breaking migration.
            $table->string('target')->default('default')->after('folder');

            // IMAP login of the source account. `mailbox` now stores the
            // configured mailbox name (the route key), not the login.
            $table->string('account')->nullable()->after('mailbox');

            // A message CC'd to several mailboxes must reach every endpoint,
            // so the idempotency key is scoped per mailbox and target.
            $table->dropUnique(['fingerprint']);
            $table->unique(['mailbox', 'target', 'fingerprint']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('relayed_messages', function (Blueprint $table) {
            $table->dropUnique(['mailbox', 'target', 'fingerprint']);
            $table->unique('fingerprint');
            $table->dropColumn(['target', 'account']);
        });
    }
};
