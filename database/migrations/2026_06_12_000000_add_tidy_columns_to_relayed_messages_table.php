<?php

declare(strict_types=1);

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
            // UID of the message in its IMAP folder at capture time, used by
            // `mailspoon:tidy` to find the message again for after-relay
            // actions. UIDs are not stable across UIDVALIDITY changes, so
            // tidy re-verifies identity before acting.
            $table->unsignedBigInteger('uid')->nullable()->after('folder');

            // When the after-relay action for the final outcome was applied
            // (or recognized as inapplicable). Null = not processed yet.
            $table->timestamp('tidied_at')->nullable()->index()->after('delivered_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('relayed_messages', function (Blueprint $table) {
            $table->dropColumn(['uid', 'tidied_at']);
        });
    }
};
