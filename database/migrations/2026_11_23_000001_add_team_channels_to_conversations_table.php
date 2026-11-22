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
        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE conversations ADD UNIQUE INDEX conversations_project_team_unique (project_id, (IFNULL(team_id, 0)))');

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropUnique(['project_id']);
        });

        DB::table('conversations')->insertUsing(
            ['project_id', 'team_id', 'type', 'created_at', 'updated_at'],
            DB::table('project_members')->select('project_id', 'team_id')
                ->selectRaw('?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP', [ConversationType::Team->value])
                ->whereNotNull('team_id')->distinct(),
        );
    }

    public function down(): void
    {
        DB::table('conversations')->where('type', ConversationType::Team->value)->delete();

        Schema::table('conversations', function (Blueprint $table): void {
            $table->unique('project_id');
        });

        DB::statement('ALTER TABLE conversations DROP INDEX conversations_project_team_unique');

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
