# Collabspace API — SPEC (v1)

Single source of truth. If code and SPEC disagree, report it; do not silently pick one.

## 1. Product

Collabspace is a web platform for remote teams. It replaces a pile of separate tools with one workspace:
**projects, kanban tasks, file sharing, chat, meetings (with transcript + AI summary), reports and an AI assistant.**

Problems it solves: scattered communication, unorganized tasks and tracking, files lost in chat,
hard meeting scheduling, and managers lacking visibility into progress.

Two kinds of users drive the rules:
- **Team member** (e.g. a remote developer): wants his tasks, files and chat without switching apps; only relevant notifications.
- **Project leader / manager**: wants to see who works on what, progress, deadlines and blockers.

Everything is organized around the **project**: a project has members (each in a team: UI/UX, Frontend, Backend, Marketing),
tasks, files, one chat channel and (optionally) meetings. **Being a member of a project is the permission boundary for almost everything.**

Frontend is a separate SPA that talks to this API with a Bearer token. The design (Figma) is the reference for fields.

## 2. Roles and permissions

There are **no global roles**. The role lives on the project membership: `leader` or `member`.

| Action | Leader | Member | Non-member |
|---|---|---|---|
| See project, tasks, files, members, channel | yes | yes | no (403) |
| Edit / delete project, mark done | yes | no | no |
| Add / remove / change members | yes | no | no |
| Create task | yes | yes | no |
| Edit task | yes | creator or assignee | no |
| Delete task | yes | creator | no |
| Move task status | see R4 | see R4 | no |
| Comment on task | yes | yes | no |
| Edit / delete own comment | yes | yes | no |
| Delete any comment | yes | no | no |
| Upload file | yes | yes | no |
| Delete file | yes | uploader | no |

Anything not listed (chat, meetings, notifications, reports, AI) follows its own rule below.

## 3. Business rules (ids are referenced in code comments and tests)

**R1 Project ownership.** The creator becomes a `leader`. A project must always keep at least one leader:
removing or demoting the last leader is rejected (422).

**R2 Membership boundary.** Only members can read anything that belongs to a project. A project can only be
discovered by its members (lists never include other projects). Non-member access => 403.

**R3 Task people.** Assignees and the reviewer must be members of the task's project (422 otherwise).
A task can have 0..n assignees and 0..1 reviewer.
When a member is removed from a project, they are also removed from the assignees of that project's tasks and cleared as reviewer.

**R4 Task status flow.** Statuses: `todo`, `in_progress`, `in_review`, `completed`.
- Moving between `todo`, `in_progress`, `in_review` is allowed for leader, creator and assignees.
- Moving **to `completed`**: allowed for the reviewer if one is set; a leader can always do it;
  if no reviewer is set, an assignee can do it too. Anyone else => 403.
- Moving **out of `completed`** (reopen): reviewer or leader only.
- Entering `completed` sets `progress = 100` and `completed_at = now`. Leaving it clears `completed_at`.
- `progress` (0..100) is a manual field for in-progress tasks; it is forced to 100 on completion.

**R5 Project progress.** `progress_percent = completed tasks / total tasks * 100` (0 when no tasks). Computed, never stored.
Project `status` is `active` or `done`, set by a leader. Marking `done` requires every task to be `completed` (or no tasks) => else 422.

**R6 Dates.** `end_date >= start_date` for projects. `due_date >= start_date` for tasks (when both given).

**R7 Files.** A file belongs to one project and optionally to one task of the same project (422 otherwise).
Max 10 MB. Allowed extensions (and matching MIME): `pdf, doc, docx, xls, xlsx, ppt, pptx, png, jpg, jpeg, webp, txt, csv, md, json, sql, zip`.
Anything else (php, html, svg, exe, sh, js...) is rejected (422). Stored on the private disk with a random name; original name kept in DB.
Download only via authorized endpoint (R2). Deleting a project removes its stored files.

**R8 Chat.**
- Each project has exactly one **project channel** (conversation type `project`), created together with the project.
  Its participants are the project members, derived from membership (no participant rows). Losing membership = losing access immediately.
- Each team that has at least one member in a project gets a **team channel** (conversation type `team`, unique per project and team). It is created the first time a member is placed in that team
  (project creation, adding a member, changing a member's team). Its participants are the project members whose `team_id` equals the channel's team (derived; changing team or leaving the project removes access immediately).
  Team channels are never deleted on their own; they disappear with the project.
- **Direct** conversations are between exactly two users, unique per pair, get-or-create. Allowed only if the two users
  share at least one project (else 403).
- A message needs a `body` (max 5000) or an attachment (same whitelist/limit as R7, stored privately). Only participants can read or post.
- Messages are returned newest-first with cursor pagination. No realtime in v1 (clients poll).

**R9 Meetings.**
- A meeting has `title`, `starts_at`, `duration_minutes` (15..480), optional `meeting_url` (https only), optional `note` (the goal), optional `project_id`.
- The creator is the organizer and an automatic participant. If `project_id` is set, the organizer must be a member and all participants must be members.
- A meeting is visible only to its organizer and participants. Update/delete: organizer only. `starts_at` must be in the future on creation.
- **Transcript**: entries `{speaker_id?, offset_seconds, text}`. Organizer and participants can add entries and read them (with `?q=` search).
- **Summary**: text field on the meeting. The organizer can generate it from the transcript via the AI service (needs at least one transcript entry, else 422).

**R10 Notifications** (Laravel database notifications): created when a user is (a) added to a project, (b) assigned to a task,
(c) invited to a meeting, and by a scheduled command when (d) a meeting starts within 30 minutes (once per meeting, to its participants who still have access to the project) or (e) a task is overdue (once per overdue episode, to its assignees; the flag is cleared when the due date moves to the future or the task is completed). Never notify the actor about their own action. A user only reads/marks their own.

**R11 Reports** (scope = projects the user is a member of, optional `project_id` must be one of them, `period` = `today|week|month|three_months|year`, default `year`):
- `task_completion_rate` = completed tasks / all tasks in scope, in %.
- `projects_completion_rate` = projects with status `done` / all projects in scope, in %.
- `teams_activity_rate` = members who completed a task or wrote a comment in the period / all members in scope, in %.
- `tasks_vs_deadline` (per month): % of tasks completed in that month **that have a `due_date`** that were completed on or before it. Completed tasks without a `due_date` are excluded from both numerator and denominator; the month value is `null` when no such task exists.
- `projects_progress` (per month): cumulative completed tasks / all tasks created up to that month end, in %.
- `worker_activity` (per month): number of tasks completed in that month.
- Period windows end now: `week` = 7 days, `month` = 30, `three_months` = 90, `year` = 365 (UTC). The previous period is the same length right before.
  Rates are evaluated *as of the end of a window*: tasks `completed_at <= end` / tasks `created_at <= end`; projects `completed_at <= end` / projects `created_at <= end`.
  Monthly series use the calendar months touched by the window. `teams_activity_rate` uses the current members.
- `today` runs from midnight UTC to now and compares against midnight yesterday to the same UTC time yesterday.
  The series keep a single bucket labeled with the current month. `tasks_vs_deadline` and `worker_activity` use today's completions, and `projects_progress` stays cumulative up to now.
- Each rate also returns `change_vs_last_period` (difference in percentage points against the previous period of the same length).
- A **submitted report** stores `type` (`project_progress|task_performance|team_activity`), `note`, date range, optional project,
  and a JSON snapshot of the computed stats at submit time. Only its author can read it.

**R12 AI assistant.** One thread per user. Each new message sends the system prompt + the last 20 messages to an OpenAI-compatible
chat-completions endpoint configured by env (`AI_BASE_URL`, `AI_API_KEY`, `AI_MODEL`). No user/project data is added to the prompt.
Message max 4000 chars. Rate limit 20/min per user. If not configured or the provider fails => 503 `{ "message": "AI assistant is unavailable." }`
and the user message is not stored. Tests fake the HTTP call.

**R13 Dashboard** (all for the current user): task counts by status for tasks assigned to the user (`pending` = `todo`), `completion_rate`
(= completed / all assigned), `completed_this_month` and `completed_last_month`; active projects (max 5) with progress and task counts;
recent files (5) from the user's projects; my tasks due soonest (5, not completed); upcoming meetings (5);
`project_overview` chart = tasks completed per month this year across the user's projects.

**R14 Search.** `GET /search?q=` (min 2 chars) returns up to 5 projects, 5 tasks and 5 files, **only within the user's projects**. Escape LIKE wildcards.

**R15 Google sign-in.** The client sends a Google ID token. The server verifies the RS256 signature against Google's public keys (cached for one hour; refetched when the `kid` is unknown, at most once per minute),
`iss` (`accounts.google.com` or `https://accounts.google.com`), `aud` = `GOOGLE_CLIENT_ID`, `exp`, and requires `email_verified = true`.
Matching order: (1) a user with the same `google_id` (the token's `sub`); (2) a user with the same email, which is linked only when Google is authoritative for that address (an `@gmail.com` address or a token with the `hd` claim), otherwise the request is rejected with 409.
When an existing account is linked and its email was never verified (password registration has no verification), its password is replaced by a random one and its tokens are revoked, because someone else may have registered the address first;
(3) otherwise a new user (name from the token, random password, `email_verified_at` set). An account already linked to a different Google id is rejected (409). Nothing from the token other than name, email and `sub` is stored; the avatar URL is ignored.

## 4. Data model

Auto-increment bigint ids, `created_at/updated_at` everywhere, FKs with sensible `cascadeOnDelete`, indexes on every FK and on common filters.
Enums are lowercase snake strings.

- **users**: name, email (unique), password, phone?, job_title?, years_of_experience?, bio?, availability_status (`available|busy|away`, default `available`), avatar_path?, google_id? (unique, up to 255 chars)
- **teams**: name — seeded: UI/UX, Frontend, Backend, Marketing.
- **projects**: name, description?, type? (free text e.g. "SaaS"), priority (`low|medium|high`), status (`active|done`, default `active`), start_date, end_date, created_by (user), completed_at? (set when status becomes `done`, cleared when it goes back to `active`).
- **project_members**: project_id, user_id, role (`leader|member`), team_id?, job_title? — unique(project_id, user_id).
- **tasks**: project_id, title, description?, status, priority, progress (0..100, default 0), start_date?, due_date?, reviewer_id?, created_by, completed_at?, overdue_notified_at?
- **task_assignees**: task_id, user_id — unique pair.
- **task_comments**: task_id, user_id, body (max 2000).
- **files**: project_id, task_id?, uploaded_by, original_name, path, mime_type, size.
- **conversations**: type (`project|team|direct`), project_id? (project and team channels), team_id? (team channels only; unique per project and team, and one project channel per project), user_one_id?/user_two_id? (direct, stored as lower id first, unique pair).
- **messages**: conversation_id, user_id, body?, attachment_path?, attachment_name?, attachment_mime?, attachment_size?
- **meetings**: project_id?, created_by, title, starts_at, duration_minutes, meeting_url?, note?, summary?, reminder_sent_at?
- **meeting_participants**: meeting_id, user_id — unique pair.
- **meeting_transcripts**: meeting_id, speaker_id?, offset_seconds, text.
- **notifications**: Laravel default table.
- **reports**: user_id, project_id?, type, note?, start_date, end_date, snapshot (json).
- **ai_messages**: user_id, role (`user|assistant`), content.

## 5. API contract

Base: `/api/v1`. JSON in/out. Auth: `Authorization: Bearer <token>` (Sanctum personal access tokens).
Stage tag in brackets = the stage that implements it.

### Auth & profile — public: register, login, google, forgot-password, reset-password
- `POST /auth/register` [S1] — name, email, password (+confirmation) → 201 `{user, token, token_type, expires_at}`
- `POST /auth/login` [S1] — email, password, remember? → 200 same shape. Wrong credentials => 401 with one generic message.
- `POST /auth/logout` [S1] — revokes the current token.
- `POST /auth/forgot-password` [S1] — always 200 with a generic message.
- `POST /auth/reset-password` [S1] — token, email, password (+confirmation); revokes all tokens.
- `POST /auth/google` [S10] — `id_token` (+ optional `remember`), verified on the server (see R15). Same response as login. 401 for any invalid token, 503 when Google sign-in is not configured.
- `GET /me` · `PUT /me` · `POST /me/avatar` · `PUT /me/password` [S1] (email is read-only in v1)
- `GET /users?search=` [S1] — workspace user search for adding members/guests. Min 2 chars. Returns `id, name, avatar_url, job_title` only.
- `GET /me/stats` [S3] — completed tasks count for the profile header.

### Projects
- `GET /teams` [S2]
- `GET /projects` [S2] — `search`, `status`, `sort=newest|oldest`, `per_page`; each item has members (first 4), members_count; `files_count` and `progress_percent` are added in S3 (they depend on files/tasks).
- `POST /projects` [S2] — name, description?, type?, priority, start_date, end_date, `members[]?` (`user_id, team_id?, job_title?, role?`)
- `GET /projects/{project}` [S2] — overview: dates, priority, status, type, members; `progress_percent` and tasks counts by status are added in S3.
- `PUT /projects/{project}` · `DELETE /projects/{project}` [S2]
- `GET /projects/{project}/members` · `POST` · `PUT /projects/{project}/members/{user}` · `DELETE ...` [S2]
- `GET /projects/{project}/teams` [S3] — teams with members and each member's tasks done/total (Teams tab and dashboard widget).

### Tasks, comments, files
- `GET /projects/{project}/tasks` · `POST /projects/{project}/tasks` [S3] — list filters include `status, priority, assignee_id, assigned_to_me, team_id, search`
- `GET /tasks` [S3] — across my projects, with `project_id, assigned_to_me, team_id, status, search`. For both lists, `team_id` matches at least one assignee whose membership in the task's project belongs to that team.
- `GET /tasks/{task}` · `PUT /tasks/{task}` · `DELETE /tasks/{task}` [S3] — assignees via `assignee_ids[]`, reviewer via `reviewer_id`
- `PATCH /tasks/{task}/status` [S3] — `status`, optional `progress` (R4)
- `GET /tasks/{task}/comments` · `POST /tasks/{task}/comments` · `PUT /comments/{comment}` · `DELETE /comments/{comment}` [S3]
- `GET /projects/{project}/files` (`search`, `uploaded_by_me`) · `POST /projects/{project}/files` (multipart `file`, `task_id?`) [S3]
- `GET /files` [S3] (files in my projects, filters `project_id`, `search`, `uploaded_by_me`) · `GET /files/{file}/download` · `DELETE /files/{file}` [S3]. For both lists, `uploaded_by_me=1` limits the results to the current user's uploads.

### Meetings, chat, notifications
- `GET /meetings?from=&to=` · `POST /meetings` · `GET /meetings/{meeting}` · `PUT` · `DELETE` [S4]
- `GET /meetings/{meeting}/transcript?q=` · `POST /meetings/{meeting}/transcript` [S4]
- `POST /meetings/{meeting}/summary/generate` [S5]
- `GET /conversations` [S4] — inbox: project channels + team channels + direct chats, each with last message, ordered by last activity
- `POST /conversations/direct` [S4] — `user_id`
- `GET /conversations/{conversation}/messages` · `POST /conversations/{conversation}/messages` [S4]
- `GET /messages/{message}/attachment` [S4]
- `GET /notifications` (with `unread_count`) · `POST /notifications/{id}/read` · `POST /notifications/read-all` [S4]

### Dashboard, reports, search, AI
- `GET /dashboard` [S5] · `GET /reports/overview` [S5] · `POST /reports` · `GET /reports` · `GET /reports/{report}` [S5]
- `GET /search?q=` [S5]
- `GET /ai/messages` · `POST /ai/messages` · `DELETE /ai/messages` [S5]

## 6. Cross-cutting conventions

- **Success shape**: API Resources (`{ "data": ... }`); paginated lists use Laravel's `data/links/meta`.
- **Errors**: 401 unauthenticated, 403 forbidden, 404 not found, 422 `{message, errors}`, 429 throttled, 503 AI unavailable.
  Always JSON for `/api/*`. No stack traces when `APP_DEBUG=false`.
- **Rate limits**: login 5/min per email+IP; register 5/min per IP; forgot/reset password 3/min per IP;
  general API 120/min per user; AI 20/min per user; file upload 20/min per user.
- **Tokens**: Sanctum; `remember=false` → expires in 1 day, `remember=true` → 30 days; password change/reset revokes other/all tokens.
- **Dates**: ISO 8601 UTC. **Files**: private disk; avatars on the public disk with random names.
- **Naming**: snake_case JSON keys, plural resource routes, enums as lowercase strings.

## 7. Out of scope for v1 (do not build)

Daily status updates, table/timeline task views, chat threads/calls/realtime, file versioning, workflows,
invites by email, email change, recent searches (client side), "hours worked", language switching (client side),
soft deletes, multi-tenant workspaces.
