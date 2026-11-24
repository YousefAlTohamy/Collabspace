<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('status_date');
            $table->text('progress');
            $table->text('next_steps');
            $table->text('blockers')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'status_date', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_statuses');
    }
};
