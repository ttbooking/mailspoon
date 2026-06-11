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
        Schema::create('relay_cursors', function (Blueprint $table) {
            $table->id();

            $table->string('mailbox');
            $table->string('folder');

            // UIDs are only comparable within one UIDVALIDITY epoch; when the
            // server renumbers, the cursor restarts and deduplication guards
            // against re-delivery.
            $table->unsignedBigInteger('uidvalidity')->default(0);
            $table->unsignedBigInteger('last_uid')->default(0);

            $table->timestamps();

            $table->unique(['mailbox', 'folder']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('relay_cursors');
    }
};
