<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            $table->timestamp('reminder_sent_at')->nullable();
            $table->index(['reminder_sent_at', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            $table->dropIndex(['reminder_sent_at', 'starts_at']);
            $table->dropColumn('reminder_sent_at');
        });
    }
};
