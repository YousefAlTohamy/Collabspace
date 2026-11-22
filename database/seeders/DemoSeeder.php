<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\ComputeReportStats;
use App\Enums\AvailabilityStatus;
use App\Enums\ConversationType;
use App\Enums\ProjectPriority;
use App\Enums\ProjectRole;
use App\Enums\ReportType;
use App\Enums\TaskStatus;
use App\Models\Conversation;
use App\Models\Meeting;
use App\Models\Message;
use App\Models\Project;
use App\Models\Report;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AddedToProject;
use App\Notifications\MeetingInvitation;
use App\Notifications\TaskAssigned;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data cannot be seeded in production.');
        }

        $now = CarbonImmutable::now('UTC');
        DB::transaction(function () use ($now): void {
            $users = $this->createUsers();
            $alpha = Project::factory()->for($users['mohamed'], 'creator')->create([
                'name' => 'Alpha',
                'type' => 'SaaS',
                'description' => 'A collaboration workspace for remote teams.',
                'priority' => ProjectPriority::High,
                'created_at' => $now->subMonthsNoOverflow(5),
                'start_date' => $now->subWeeks(6)->toDateString(),
                'end_date' => $now->addWeeks(6)->toDateString(),
            ]);
            $sepetGo = Project::factory()->for($users['mohanad'], 'creator')->done()->create([
                'name' => 'SepetGo',
                'description' => 'A mobile storefront pilot.',
                'priority' => ProjectPriority::Medium,
                'start_date' => $now->subWeeks(11)->toDateString(),
                'end_date' => $now->subWeeks(2)->toDateString(),
                'created_at' => $now->subWeeks(11),
                'completed_at' => $now->subWeeks(2),
                'updated_at' => $now->subWeeks(2),
            ]);

            $this->addMembers($alpha, $users, [
                'mohamed' => ['UI/UX', 'Leader'],
                'mohanad' => ['Backend', 'API Engineer'],
                'sara' => ['UI/UX', 'UI Designer'],
                'ali' => ['Frontend', 'React Dev'],
                'khalid' => ['Backend', 'Backend Dev'],
                'mahmoud' => ['Marketing', 'Marketing Lead'],
            ]);
            $this->addMembers($sepetGo, $users, [
                'mohanad' => ['Backend', 'Leader'],
                'mohamed' => ['UI/UX', 'UX Researcher'],
                'sara' => ['UI/UX', 'UI Designer'],
            ]);

            $tasks = $this->createAlphaTasks($alpha, $users, $now);
            $this->createSepetGoTasks($sepetGo, $users, $now);
            $this->createChat($alpha, $users, $now);
            $invitation = $this->createMeetings($alpha, $users, $now);
            $this->createNotificationsAndReport($alpha, $sepetGo, $tasks[0], $invitation, $users, $now);
            $this->storeFiles($alpha, $users['mohamed'], $tasks);
        });
    }

    /** @return array<string, User> */
    private function createUsers(): array
    {
        $password = Hash::make('Password123');
        $accounts = [
            'mohamed' => ['Mohamed Salah', 'UI/UX Designer'],
            'mohanad' => ['Mohanad Wahib', 'Backend Engineer'],
            'sara' => ['Sara Hassan', 'UI Designer'],
            'ali' => ['Ali Mohamed', 'Frontend Developer'],
            'khalid' => ['Khalid Mousa', 'Backend Developer'],
            'mahmoud' => ['Mahmoud Salah', 'Marketing Lead'],
        ];
        $users = [];
        foreach ($accounts as $handle => [$name, $jobTitle]) {
            $users[$handle] = User::factory()->create([
                'name' => $name,
                'email' => $handle.'@collabspace.test',
                'password' => $password,
                'job_title' => $jobTitle,
                'availability_status' => AvailabilityStatus::Available,
                'avatar_path' => null,
            ]);
        }

        return $users;
    }

    /** @param array<string, User> $users */
    private function addMembers(Project $project, array $users, array $members): void
    {
        $teamIds = Team::query()->pluck('id', 'name');
        foreach ($members as $handle => [$team, $jobTitle]) {
            $user = $users[$handle];
            $project->members()->updateOrCreate(['user_id' => $user->id], [
                'team_id' => $teamIds->get($team),
                'job_title' => $jobTitle,
                'role' => $user->id === $project->created_by ? ProjectRole::Leader : ProjectRole::Member,
            ]);
        }
    }

    /** @return list<Task> */
    private function createAlphaTasks(Project $project, array $users, CarbonImmutable $now): array
    {
        $definitions = [
            ['Create wireframes', TaskStatus::Todo, 'mohamed', 0],
            ['User research analysis', TaskStatus::Todo, 'sara', 0],
            ['Design system updates', TaskStatus::Todo, 'mohamed', 0],
            ['Accessibility audit', TaskStatus::Todo, 'ali', 0],
            ['Sprint acceptance notes', TaskStatus::Todo, 'mahmoud', 0],
            ['Chat screen prototyping', TaskStatus::InProgress, 'mohamed', 62],
            ['Create Miro interaction', TaskStatus::InProgress, 'sara', 32],
            ['Dashboard filters', TaskStatus::InProgress, 'ali', 45],
            ['Files API', TaskStatus::InProgress, 'khalid', 70],
            ['Mobile responsiveness', TaskStatus::InReview, 'mohamed', 90, 'ali'],
            ['Report charts', TaskStatus::InReview, 'mohanad', 85, 'khalid'],
            ['Keyboard navigation', TaskStatus::InReview, 'ali', 95, 'sara'],
            ['Login screen', TaskStatus::Completed, 'mohamed', 100],
            ['Survey', TaskStatus::Completed, 'mohamed', 100],
            ['Design database schema', TaskStatus::Completed, 'mohanad', 100],
            ['Auth endpoints', TaskStatus::Completed, 'khalid', 100],
            ['Competitor research', TaskStatus::Completed, 'mahmoud', 100],
            ['Project layout', TaskStatus::Completed, 'ali', 100],
            ['Component inventory', TaskStatus::Completed, 'sara', 100],
            ['Interview script', TaskStatus::Completed, 'mohamed', 100],
        ];
        $tasks = [];
        $completedIndex = 0;
        foreach ($definitions as $index => $definition) {
            [$title, $status, $assignee, $progress] = $definition;
            $reviewer = $definition[4] ?? null;
            $completed = $status === TaskStatus::Completed ? $now->subDays(3 + 20 * $completedIndex++) : null;
            $created = $completed?->subDays(5) ?? $now->subDays(35 - $index);
            $due = $completed === null ? $now->addDays($index - 7) : $completed->addDays($index % 2 === 0 ? 2 : -1);
            $tasks[] = $task = Task::factory()->for($project)->create([
                'title' => $title,
                'status' => $status,
                'progress' => $progress,
                'start_date' => $created->toDateString(),
                'due_date' => $due->toDateString(),
                'reviewer_id' => $reviewer === null ? null : $users[$reviewer]->id,
                'created_at' => $created,
                'completed_at' => $completed,
                'updated_at' => $completed ?? $now,
            ]);
            $task->assignees()->attach($users[$assignee]);

            if ($index < 6) {
                TaskComment::factory()->for($task)->for($users['sara'], 'user')->create([
                    'body' => 'Please capture the decision and next step here.',
                    'created_at' => $now->subDays($index + 1),
                ]);
            }
        }

        return $tasks;
    }

    private function createSepetGoTasks(Project $project, array $users, CarbonImmutable $now): void
    {
        $assignees = [$users['mohanad'], $users['mohamed'], $users['sara']];
        foreach (['Browse catalog', 'Checkout flow', 'Product cards', 'Order API', 'Mobile layout', 'Pilot review'] as $index => $title) {
            $completed = $now->subDays(15 + 6 * $index);
            $created = $completed->subDays(5);
            Task::factory()->for($project)->completed()->create([
                'title' => $title,
                'created_at' => $created,
                'start_date' => $created->toDateString(),
                'due_date' => $completed->addDays($index % 2 === 0 ? 2 : -1)->toDateString(),
                'completed_at' => $completed,
                'updated_at' => $completed,
            ])->assignees()->attach($assignees[$index % count($assignees)]);
        }
    }

    private function createChat(Project $alpha, array $users, CarbonImmutable $now): void
    {
        $channel = Conversation::query()->where('project_id', $alpha->id)->sole();
        $direct = Conversation::query()->create([
            'type' => ConversationType::Direct,
            'user_one_id' => min($users['mohamed']->id, $users['sara']->id),
            'user_two_id' => max($users['mohamed']->id, $users['sara']->id),
        ]);
        $messages = [
            [$channel, 'mohamed', 'Please post anything blocking this sprint.'],
            [$channel, 'sara', 'Wireframes are ready for review.'],
            [$channel, 'ali', 'I will review the mobile layout today.'],
            [$channel, 'khalid', 'The upload endpoint is ready to integrate.'],
            [$channel, 'mohanad', 'I added the report examples to the sprint board.'],
            [$channel, 'mahmoud', 'The pilot feedback is in the research notes.'],
            [$direct, 'mohamed', 'Could you bring two options to the design review?'],
            [$direct, 'sara', 'Yes, I will include the keyboard navigation flow.'],
            [$direct, 'mohamed', 'Thanks. We can compare them in the meeting.'],
        ];
        foreach ($messages as $index => [$conversation, $sender, $body]) {
            Message::factory()->for($conversation)->for($users[$sender], 'user')->create([
                'body' => $body,
                'created_at' => $now->subMinutes(60 - $index),
            ]);
        }
    }

    private function createMeetings(Project $project, array $users, CarbonImmutable $now): Meeting
    {
        $definitions = [
            ['Design review', $now->addDay()->setTime(10, 0), 'mohanad', ['mohamed', 'sara', 'ali']],
            ['Backend sync', $now->addDays(3)->setTime(10, 0), 'mohamed', ['mohanad', 'khalid']],
            ['Pilot planning', $now->addWeek()->setTime(10, 0), 'mohamed', ['sara', 'mahmoud']],
            ['Sprint retrospective', $now->subDays(4)->setTime(10, 0), 'mohamed', ['mohanad', 'sara', 'ali']],
            ['Demo reminder', $now->addMinutes(20), 'mohamed', ['sara', 'ali']],
        ];
        $meetings = [];
        foreach ($definitions as [$title, $startsAt, $organizer, $participants]) {
            $meeting = Meeting::factory()->for($project)->for($users[$organizer], 'organizer')->create([
                'title' => $title,
                'starts_at' => $startsAt,
                'duration_minutes' => 30,
                'meeting_url' => 'https://meet.example.com/'.Str::slug($title),
            ]);
            $meeting->participants()->attach(array_map(fn (string $handle): int => $users[$handle]->id, $participants));
            $meetings[] = $meeting;
        }

        $past = $meetings[3];
        $past->transcript()->createMany([
            ['speaker_id' => $users['mohamed']->id, 'offset_seconds' => 0, 'text' => 'Let us review the first sprint.'],
            ['speaker_id' => $users['sara']->id, 'offset_seconds' => 20, 'text' => 'The new navigation tested well with users.'],
            ['speaker_id' => $users['ali']->id, 'offset_seconds' => 40, 'text' => 'We should check the mobile layout before the pilot.'],
            ['speaker_id' => $users['mohanad']->id, 'offset_seconds' => 60, 'text' => 'The API is ready for the next integration pass.'],
            ['speaker_id' => $users['sara']->id, 'offset_seconds' => 80, 'text' => 'I will update the wireframes.'],
            ['speaker_id' => $users['mohamed']->id, 'offset_seconds' => 100, 'text' => 'We will review the changes at the next design meeting.'],
        ]);
        $past->summary = "- Confirmed the navigation tested well.\n- Check the mobile layout and update the wireframes before the pilot.\n- Review the integration and designs at the next meeting.";
        $past->save();

        return $meetings[0];
    }

    private function createNotificationsAndReport(
        Project $alpha,
        Project $sepetGo,
        Task $task,
        Meeting $meeting,
        array $users,
        CarbonImmutable $now,
    ): void {
        $mohamed = $users['mohamed'];
        $actor = $users['mohanad'];
        $mohamed->notify(new AddedToProject($sepetGo->id, $actor));
        $mohamed->notify(new TaskAssigned($task->id, $alpha->id, $actor));
        $mohamed->notify(new MeetingInvitation($meeting->id, $alpha->id, $actor));
        $mohamed->notifications()->where('type', 'project.member_added')->firstOrFail()->markAsRead();

        $from = $now->subMonthsNoOverflow(5)->startOfDay();
        $to = $now->endOfDay();
        $report = new Report([
            'type' => ReportType::TaskPerformance,
            'note' => 'Alpha task performance and delivery dates.',
            'start_date' => $from->toDateString(),
            'end_date' => $to->toDateString(),
        ]);
        $report->user_id = $mohamed->id;
        $report->project_id = $alpha->id;
        $report->snapshot = app(ComputeReportStats::class)->execute($mohamed, $alpha, $from, $to);
        $report->save();
    }

    /** @param list<Task> $tasks */
    private function storeFiles(Project $project, User $uploader, array $tasks): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC');
        $files = [
            ['UX_Research_Summary.pdf', 'application/pdf', $this->researchPdf(), $tasks[1]->id],
            ['Database_Schema_v2.sql', 'text/plain', "CREATE TABLE demo_notes (id BIGINT PRIMARY KEY, body TEXT);\n", $tasks[14]->id],
            ['Design_Mockups.png', 'image/png', $png, $tasks[0]->id],
            ['Pilot_Notes.md', 'text/plain', "# Pilot notes\n\nReview the mobile task flow before the pilot.\n", null],
        ];
        $disk = Storage::disk('local');
        foreach ($files as [$name, $mime, $content, $taskId]) {
            $path = 'projects/'.$project->id.'/'.Str::random(40).'.'.pathinfo($name, PATHINFO_EXTENSION);
            if (! $disk->put($path, $content)) {
                throw new RuntimeException('Could not write a demo attachment.');
            }

            $file = $project->files()->make([
                'task_id' => $taskId,
                'original_name' => $name,
                'mime_type' => $mime,
                'size' => strlen($content),
            ]);
            $file->path = $path;
            $file->uploaded_by = $uploader->id;
            $file->save();
        }
    }

    private function researchPdf(): string
    {
        $stream = "BT /F1 14 Tf 24 64 Td (Collabspace UX Research Summary) Tj ET\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 320 100] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream',
        ];
        $pdf = "%PDF-1.4\n";
        $xref = "0000000000 65535 f \n";
        foreach ($objects as $index => $object) {
            $xref .= sprintf("%010d 00000 n \n", strlen($pdf));
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        return $pdf."xref\n0 6\n".$xref."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".strlen($pdf)."\n%%EOF\n";
    }
}
