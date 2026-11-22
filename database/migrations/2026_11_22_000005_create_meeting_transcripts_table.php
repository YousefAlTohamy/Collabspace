<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_transcripts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('speaker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('offset_seconds');
            $table->text('text');
            $table->index(['meeting_id', 'offset_seconds']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_transcripts');
    }
};
