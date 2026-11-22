<?php

declare(strict_types=1);

use App\Enums\ConversationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 16);
            $table->foreignId('project_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_one_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_two_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->unique(['user_one_id', 'user_two_id']);
            $table->timestamps();
        });

        DB::table('conversations')->insertUsing(
            ['project_id', 'type', 'created_at', 'updated_at'],
            DB::table('projects')->select('id')
                ->selectRaw('?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP', [ConversationType::Project->value])
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
