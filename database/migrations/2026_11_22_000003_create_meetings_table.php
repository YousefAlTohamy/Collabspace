<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title', 150);
            $table->dateTime('starts_at')->index();
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('meeting_url', 2048)->nullable();
            $table->text('note')->nullable();
            $table->text('summary')->nullable();
            $table->index('created_by');
            $table->index('project_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
