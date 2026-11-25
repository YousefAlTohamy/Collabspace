# Collabspace API

![CI](https://github.com/YousefAlTohamy/Collabspace/actions/workflows/ci.yml/badge.svg)

Backend API for **Collabspace**, a team collaboration platform for remote teams. It brings projects, kanban tasks, file sharing, chat,
meetings (with transcript and AI summary), reports and an AI assistant into one workspace, so teams stop switching between separate tools.

Built with **Laravel 13**, **PHP 8.4**, **MySQL 8.4**, **Laravel Sanctum** and **Docker (Laravel Sail)**.
The full business rules and the API contract are in [`docs/SPEC.md`](docs/SPEC.md).

## Hosted demo for judges

The Railway demo uses seeded sample data and is ready to try in Postman without Docker, Google sign-in, or an AI key.

**API base URL** `https://collabspace-demo-production.up.railway.app/api/v1` · [Check service health](https://collabspace-demo-production.up.railway.app/up)

1. In Postman Desktop, choose **Import** and paste the [direct collection URL](https://raw.githubusercontent.com/YousefAlTohamy/Collabspace/main/postman/Collabspace.Hosted-Demo.postman_collection.json). The collection already has the hosted `baseUrl`. Leave the environment selector on **No Environment**.
2. Open **Hosted AI demo**, choose **Run**, and click **Start run** with its four requests selected.

| Request | Expected result |
|---|---|
| Sign in as the demo project lead | `200`. Postman saves the bearer token for the remaining requests. |
| Ask the AI | `201` with a Gemini reply. |
| Find the seeded Sprint retrospective | `200`. Postman saves the meeting ID. |
| Generate AI summary (organizer) | `200` with a new summary of the seeded transcript. |

Use sample text with the shared demo account. If Postman reports a connection error, retry the run. If Gemini returns `503`, retry the AI request. If the Railway trial has ended, use the [local setup](#getting-started) and [seeded quick demo](#quick-demo-from-the-seeded-data). The [optional provider setup](#optional-provider-demos) enables Gemini locally.

## Review guide

| Criterion | Where to verify it |
|---|---|
| Business rules | Follow the [seeded quick demo](#quick-demo-from-the-seeded-data) to see a task assignee denied completion and the reviewer complete it. [SPEC](docs/SPEC.md) records the rules, and the [Figma coverage map](docs/FIGMA_COVERAGE.md) maps each design area to its endpoints and v1 limits. |
| Security | The quick demo also checks cross-project access with two accounts. [RouteAuthenticationTest](tests/Feature/RouteAuthenticationTest.php) checks the public route allowlist, [ProjectFileContentTest](tests/Feature/ProjectFileContentTest.php) checks real upload content, and [GoogleSignInTest](tests/Feature/GoogleSignInTest.php) checks token verification and account linking. |
| Code quality | [Architecture](#architecture) and the [ERD](docs/ERD.md) explain where the rules live and which invariants the database enforces. [GitHub Actions](https://github.com/YousefAlTohamy/Collabspace/actions/workflows/ci.yml) runs Pint and the feature suite against MySQL. |

## What the product does

| Module | What it covers |
|---|---|
| **Auth & profile** | Register, password and Google sign-in, logout, password reset, profile, avatar, password change, workspace user search |
| **Projects & teams** | Projects with members, roles (`leader` / `member`), teams (UI/UX, Frontend, Backend, Marketing) and member availability |
| **Daily Status** | One update per member per UTC day with progress, next steps, and blockers |
| **Tasks** | Kanban statuses, priorities, assignees, reviewer, progress, comments, overdue flag |
| **Files** | Project files with strict type/size checks, private storage and authorized downloads |
| **Chat** | Project and team channels, direct messages, attachments, author-only message editing, inbox and message search, cursor pagination |
| **Meetings** | Calendar, participants, transcript with search, AI-generated summary |
| **Notifications** | Project membership, task assignments, meeting invitations, overdue tasks and upcoming meeting reminders |
| **Dashboard & reports** | Personal dashboard, report statistics with period comparison, saved report snapshots |
| **Search** | One global search across projects, tasks and files |
| **AI assistant** | Per-user assistant thread (any OpenAI-compatible provider) |

## Business rules at a glance

Everything is organized around the **project**: being a member of a project is the permission boundary for almost everything.

- The creator of a project becomes its leader. A project always keeps at least one leader.
- Project members can see its tasks, files, member list and project channel. Signed-in non-members get `403` on access attempts.
- Tasks can move between `todo`, `in_progress` and `in_review` before completion. A task is completed by its **reviewer** (or a leader, or an assignee when no reviewer is set).
  Reopening a completed task is reserved for the reviewer or a leader. Completion forces progress to 100%.
- A project can only be marked `done` when all of its tasks are completed. A leader reactivates it before anyone adds or reopens a task. Project progress is computed from task states.
- Each member can submit and read their own daily update. Current project leaders can read all updates for a selected day.
- Assignees and reviewers must be members of the task's project. Removing a member also removes them from the project's assignments.
- Starting a direct chat requires a shared project. Losing membership removes access to that project's channel immediately.
- Team channels are open to members of that team in the project. Every project has one project channel open to all its members.
- Only the author can edit a message, while they still have access to the conversation. The message keeps its attachment and position in the chat.
- Meetings are visible only to the organizer and participants. Project-linked meetings also require current membership. For a meeting without a project, invitees must share a project with the organizer.
- Reports are computed from real task data (`created_at`, `completed_at`, `due_date`) for explicit time windows and compare each period with the previous one.

See [`docs/SPEC.md`](docs/SPEC.md) for all rules (R1–R16), the data model and every endpoint.

## Security

- **Authentication**: Sanctum bearer tokens expire after 1 day or 30 days with `remember`. Password change revokes other tokens and password reset revokes all tokens.
- **Authorization**: endpoints enforce object-level access through policies or queries scoped to the user's memberships or records. Cross-project access is covered by tests.
  A test fails if a new route is added without authentication.
- **Brute force protection**: rate limits on login (per email+IP and per IP), registration, password reset, uploads and the AI endpoints.
- **Password authentication** uses the same error and equivalent hashing work for wrong-password and unknown-email logins. Forgot-password returns the same message for known and unknown email addresses.
- **Google sign-in** verifies the ID token signature and claims, checks whether Google is authoritative for an existing account's email before linking it, and rate-limits signing-key refreshes (R15).
- **Project files and chat attachments**: extension whitelist and real content (MIME) check, 10 MB limit, random names, private disk, no storage path in responses,
  downloads only through an authorized endpoint with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`.
  Word, Excel and PowerPoint uploads in modern Office formats also require the expected manifest and main archive part.
- **Input handling**: Form Requests everywhere, explicit `$fillable`, server-owned fields (`created_by`, `user_id`, ...) are never read from input,
  `LIKE` wildcards are escaped, meeting links must be `https`.
- **Responses**: API Resources for models, other users' email/phone are never exposed, and errors are JSON without stack traces when `APP_DEBUG=false`.
- **AI**: the provider key is configured in `.env`. The assistant sends the user's message and bounded thread history. Meeting summaries send transcript text. Provider failures return a generic `503` without saving a message or summary.

## Architecture

```
app/
  Actions/        # real business logic (task status flow, report stats, dashboard, inbox, teams overview)
  Enums/          # statuses, priorities, roles
  Http/
    Controllers/  # thin: validate -> authorize -> act -> resource
    Requests/     # Form Requests (validation, input rules)
    Resources/    # API Resources (response shape)
  Models/         # Eloquent models and relations
  Notifications/  # database notifications
  Policies/       # authorization rules
  Services/       # external calls (AI client)
  Support/        # small shared helpers (upload rules, LIKE escaping)
database/         # migrations, factories, seeders (demo data)
tests/Feature/    # feature tests for every module
docs/SPEC.md      # business rules, data model, API contract
postman/          # ready-to-run Postman collection
```

Design choices: plain CRUD stays in controllers; a class is created only when there is real logic. There is no repository layer, no generic base classes and no event system.

## Getting started

Requirements: **Docker** (Docker Desktop on Windows/macOS).

```bash
git clone https://github.com/YousefAlTohamy/Collabspace.git
cd Collabspace
cp .env.example .env
```

Install dependencies once. On Linux, macOS or WSL2:

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html \
  laravelsail/php84-composer:latest composer install --ignore-platform-reqs
```

On Windows PowerShell:

```powershell
docker run --rm -v "${PWD}:/var/www/html" -w /var/www/html laravelsail/php84-composer:latest composer install --ignore-platform-reqs
```

Start the stack and prepare the app. Wait for MySQL to become healthy before running migrations. On Linux, macOS and WSL2 use Sail:

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail artisan storage:link
```

On Windows (Git Bash / PowerShell), where the Sail script does not run, use Docker Compose directly:

```bash
docker compose up -d --wait
docker compose exec laravel.test php artisan key:generate
docker compose exec -u sail laravel.test php artisan migrate --seed
docker compose exec laravel.test php artisan storage:link
```

Run the seed as the `sail` user so the API can read the seeded private files. Use `--seed` for the initial setup on an empty database. For later schema updates, run `migrate` without `--seed`.

The API is then available at `http://localhost:8000/api/v1` and emails (password reset) arrive in Mailpit at `http://localhost:8025`.
If port 8000 is busy, update `APP_PORT` and `APP_URL` in `.env`.
Published development services bind to `127.0.0.1` by default. Keep overrides such as `APP_PORT` and `FORWARD_DB_PORT` as port numbers. The host address is a separate setting, `SAIL_BIND_ADDRESS`.

In the `local` environment, `--seed` also loads **demo data** (see below). In any other environment only the four teams are seeded.

### Scheduled notifications

Run the scheduler worker with `docker compose exec -u sail laravel.test php artisan schedule:work` to send meeting reminders and overdue task notifications every five minutes. To check for due notifications immediately, run `docker compose exec -u sail laravel.test php artisan notifications:send-reminders`.

The reminder is sent once for the meeting's current start time. Changing the start time makes the meeting eligible for a new reminder.

In a fresh local demo database, run the command twice as soon as seeding finishes, before starting the worker. The first run sends notifications for overdue tasks and a meeting starting in 20 minutes. The second run should show zero for both counts. Sign in as Mohamed and set **List notifications (saves notificationId)** to **Inherit auth from parent** to see both types of notifications. The collection's full workflow uses the helper account's token for that request.

### Environment variables

| Variable | Purpose |
|---|---|
| `APP_URL`, `FRONTEND_URL` | API URL and comma-separated frontend origins for CORS. The first origin is used for password reset links. |
| `DB_*` | MySQL connection (preconfigured for Sail) |
| `SAIL_BIND_ADDRESS` | Host address for Docker's published development ports. Defaults to `127.0.0.1`. |
| `MAIL_*` | Mail transport (Mailpit in development) |
| `GOOGLE_CLIENT_ID` | Google OAuth client ID used to verify ID tokens. Google sign-in returns `503` when this is empty. |
| `AI_BASE_URL`, `AI_API_KEY`, `AI_MODEL`, `AI_TIMEOUT` | OpenAI-compatible provider settings. Set `AI_BASE_URL` to the prefix before `/chat/completions`. Generating an AI reply or meeting summary returns `503` when the provider is unconfigured or unavailable. |

Set `APP_DEBUG=false` outside local development.

### Running the tests

```bash
./vendor/bin/sail artisan test          # Windows: docker compose exec laravel.test php artisan test
./vendor/bin/sail bin pint --test       # Windows: docker compose exec laravel.test ./vendor/bin/pint --test
```

Tests run against a real MySQL `testing` database (reports use SQL date functions). AI calls are always faked.

## Demo accounts

Password for all accounts: `Password123`

| User | Email | Role in the demo |
|---|---|---|
| Mohamed Salah | `mohamed@collabspace.test` | Leader of **Alpha** (best account to explore) |
| Mohanad Wahib | `mohanad@collabspace.test` | Backend Engineer |
| Sara Hassan | `sara@collabspace.test` | UI Designer |
| Ali Mohamed | `ali@collabspace.test` | Frontend Developer, reviewer of Mobile responsiveness |
| Khalid Mousa | `khalid@collabspace.test` | Backend Developer |
| Mahmoud Salah | `mahmoud@collabspace.test` | Marketing Lead |

The demo contains two projects (**Alpha**, active, and **SepetGo**, done), tasks in every status spread over several months, comments, files,
chat messages, upcoming meetings, a meeting with transcript and summary, notifications and a saved report.

## API documentation (Postman)

Import the [local Postman collection](postman/Collabspace.postman_collection.json) to run the full API workflow against your own seeded database. The default full run needs no Google or AI keys. Keep `aiMode=unconfigured` with a fresh `.env` and leave the Google ID token placeholder in place. The collection checks both AI endpoints return the documented `503`. The rest of the workflow exercises password sign-in and the regular API.

To run the full collection from the repository root with Node.js installed:

```bash
npx --yes newman@6.2.3 run postman/Collabspace.postman_collection.json --working-dir .
```

On Windows PowerShell, use `npx.cmd` in place of `npx` if script execution is disabled.

The default `baseUrl` is `http://localhost:8000/api/v1`. Use `--env-var "baseUrl=http://localhost:YOUR_PORT/api/v1"` if the port differs. The runner creates two demo accounts and saves the tokens and IDs for later requests. The final **Cleanup** folder deletes records created by this workflow. Run the full collection against a local demo database.

For Postman Desktop, run the folders in order. If Postman asks for local files, select [`demo-avatar.png`](postman/fixtures/demo-avatar.png) for **Upload avatar** and [`demo-upload.txt`](postman/fixtures/demo-upload.txt) for both project files and chat attachments.

### Optional provider demos

- **Gemini:** Put the API key and an available chat model in `AI_API_KEY` and `AI_MODEL` in `.env`. Set `AI_BASE_URL=https://generativelanguage.googleapis.com/v1beta/openai/`. Set the Postman variable `aiMode` to `configured`, or pass `--env-var "aiMode=configured"` to Newman. **Ask the AI** then requires `201` with a reply. **Generate AI summary (organizer)** requires `200` with a summary after the transcript has been added. [Gemini OpenAI compatibility](https://ai.google.dev/gemini-api/docs/openai)
- **Google:** Use the same `GOOGLE_CLIENT_ID` in `.env` and in the browser client, and register its origin in Google Cloud. Set the local Postman variable `googleIdToken` to a fresh ID token and run **Login with Google**. The request expects `200` and returns its own Collabspace bearer token. With the basic Sign in with Google scopes (`openid`, `email`, `profile`), users can sign in without a Test Users invite. [Google's audience settings](https://support.google.com/cloud/answer/15549945#publishing-status-testing)

### Quick demo from the seeded data

1. Set `email=mohamed@collabspace.test` and `password=Password123` in the collection variables and run **Auth → Login (saves token)**, then **Dashboard**. Open **List my projects**, find Alpha's ID and set `projectId`. Run **Project teams tab** to see availability and task counts. In **Reports overview**, enable the `project_id` query parameter to report on Alpha. You can set `period=today` to see activity since midnight UTC.
2. In **List project tasks**, use `status=in_review` and `search=Report charts`, and disable any `assigned_to_me` or priority filter. To demonstrate the team filter, set `teamId` to Backend's ID from **List teams (saves teamId)** and enable `team_id`. Save the task ID as `taskId`. On a fresh seed, sign in as Mohanad (`mohanad@collabspace.test`), the assignee. **Change status: completed** returns `403` while a reviewer is assigned. The reviewer, Khalid (`khalid@collabspace.test`), can run the same request and gets `200` with `progress: 100`.
3. As Mohamed, find SepetGo's ID in **List my projects** with the search and status filters off. As Khalid, **Get project** with that ID returns `403` because he's outside SepetGo.
4. With Alpha selected, sign in as Mohanad and run **Save my daily status**. **List daily statuses** shows his own update. Switch to Mohamed to review the team's updates for the day.
5. With Alpha still selected, run **My inbox (saves conversationId)**, **Send text message (saves messageId)** and **Edit message (author)**. List the conversation's messages again to see the updated text and `edited_at`.

The Quick demo uses password sign-in and works with the seeded database alone.

Enable `uploaded_by_me=1` in **List all my files** or **List project files** to see your own uploads in projects you can access.

Enable `priority` and `overdue=1` in either task list to find open tasks whose UTC due date has passed. In **List messages (newest first, cursor)**, enable `q` to search message text within the selected conversation.

## Endpoint overview

All 67 API routes live under `/api/v1`. Only `auth/register`, `auth/login`, `auth/google`, `auth/forgot-password` and `auth/reset-password` are public.

| Area | Endpoints |
|---|---|
| Auth | `POST /auth/register`, `/auth/login`, `POST /auth/google`, `/auth/logout`, `/auth/forgot-password`, `/auth/reset-password` |
| Profile | `GET/PUT /me`, `POST /me/avatar`, `PUT /me/password`, `GET /me/stats`, `GET /users?search=` |
| Projects | `GET/POST /projects`, `GET/PUT/DELETE /projects/{project}`, members (`GET/POST/PUT/DELETE /projects/{project}/members…`), `GET /projects/{project}/teams`, `GET /teams` |
| Daily Status | `GET /projects/{project}/daily-statuses?date=`, `PUT /projects/{project}/daily-statuses/today` |
| Tasks | `GET/POST /projects/{project}/tasks`, `GET /tasks`, `GET/PUT/DELETE /tasks/{task}`, `PATCH /tasks/{task}/status` |
| Comments | `GET/POST /tasks/{task}/comments`, `PUT/DELETE /comments/{comment}` |
| Files | `GET/POST /projects/{project}/files`, `GET /files`, `GET /files/{file}/download`, `DELETE /files/{file}` |
| Chat | `GET /conversations?q=`, `POST /conversations/direct`, `GET/POST /conversations/{conversation}/messages`, `PATCH /messages/{message}`, `GET /messages/{message}/attachment` |
| Meetings | `GET/POST /meetings`, `GET/PUT/DELETE /meetings/{meeting}`, `GET/POST /meetings/{meeting}/transcript`, `POST /meetings/{meeting}/summary/generate` |
| Notifications | `GET /notifications`, `POST /notifications/{id}/read`, `POST /notifications/read-all` |
| Insights | `GET /dashboard`, `GET /reports/overview`, `GET/POST /reports`, `GET /reports/{report}`, `GET /search?q=` |
| AI | `GET/POST/DELETE /ai/messages` |

## Decisions and limits (v1)

- **Chat** uses REST with cursor pagination, so the client polls for new messages. Project, team and direct conversations support author-only text edits. Read receipts are outside v1.
- **Meetings** store an HTTPS link for an external service such as Meet or Zoom. The calendar accepts a date range. Meeting summaries send transcript text to the configured AI provider. Meeting attachments are outside v1.
- **Reports** offer `today`, `week`, `month`, `three_months`, and `year`. Today compares the elapsed part of the UTC day with the same hours yesterday. The chart keeps its month label and project progress is cumulative.
- **Profile email** is read-only. New Google accounts receive a random password and can set a password through the reset flow. Google linking requires a Gmail address or a Workspace `hd` claim. Linking an existing account with an unverified email replaces its password and revokes its old tokens. A verified account keeps its credentials.
- **v1 scope** includes Kanban task data and daily project updates. See [SPEC §7](docs/SPEC.md#7-out-of-scope-for-v1-do-not-build) for the scope of chat, file history, and other design ideas.
