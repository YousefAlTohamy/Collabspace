# Collabspace API

Backend API for **Collabspace**, a team collaboration platform for remote teams. It brings projects, kanban tasks, file sharing, chat,
meetings (with transcript and AI summary), reports and an AI assistant into one workspace, so teams stop switching between separate tools.

Built with **Laravel 13**, **PHP 8.4**, **MySQL 8**, **Laravel Sanctum** and **Docker (Laravel Sail)**.
The full business rules and the API contract are in [`docs/SPEC.md`](docs/SPEC.md).

## What the product does

| Module | What it covers |
|---|---|
| **Auth & profile** | Register, login, logout, password reset, profile, avatar, password change, workspace user search |
| **Projects & teams** | Projects with members, roles (`leader` / `member`) and teams (UI/UX, Frontend, Backend, Marketing) |
| **Tasks** | Kanban statuses, priorities, assignees, reviewer, progress, comments, overdue flag |
| **Files** | Project files with strict type/size checks, private storage and authorized downloads |
| **Chat** | One channel per project and direct messages, attachments, cursor pagination |
| **Meetings** | Calendar, participants, transcript with search, AI-generated summary |
| **Notifications** | Added to a project, task assigned, meeting invitation |
| **Dashboard & reports** | Personal dashboard, report statistics with period comparison, saved report snapshots |
| **Search** | One global search across projects, tasks and files |
| **AI assistant** | Per-user assistant thread (any OpenAI-compatible provider) |

## Business rules at a glance

Everything is organized around the **project**: being a member of a project is the permission boundary for almost everything.

- The creator of a project becomes its leader. A project always keeps at least one leader.
- Only members can see anything that belongs to a project (tasks, files, members, chat channel, meetings). Everyone else gets `403`.
- Task status flow: `todo → in_progress → in_review → completed`. A task is completed by its **reviewer** (or a leader, or an assignee when no reviewer is set).
  Reopening a completed task is reserved for the reviewer or a leader. Completion forces progress to 100%.
- A project can only be marked `done` when all of its tasks are completed. Project progress is always computed, never stored.
- Assignees and reviewers must be members of the task's project. Removing a member also removes them from the project's assignments.
- Direct chats are only allowed between users who share at least one project. Losing project membership removes chat access immediately.
- Meetings are visible only to the organizer and participants. Participants must belong to the project, or share a project with the organizer.
- Reports are computed from real task data (`created_at`, `completed_at`, `due_date`) for explicit time windows and compare each period with the previous one.

See [`docs/SPEC.md`](docs/SPEC.md) for all rules (R1–R14), the data model and every endpoint.

## Security

- **Authentication**: Sanctum bearer tokens with expiry (1 day, or 30 days with `remember`); password change and reset revoke other tokens.
- **Authorization**: every endpoint that takes an id goes through a Policy (object-level checks); cross-project access is covered by tests.
  A test fails if a new route is added without authentication.
- **Brute force protection**: rate limits on login (per email+IP and per IP), registration, password reset, uploads and the AI endpoints.
- **No user enumeration**: the same response for unknown email and wrong password, and for forgot-password; login does equivalent hashing work on both paths.
- **Uploads**: extension whitelist and real content (MIME) check, 10 MB limit, random names, private disk, no storage path in responses,
  downloads only through an authorized endpoint with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`.
- **Input handling**: Form Requests everywhere, explicit `$fillable`, server-owned fields (`created_by`, `user_id`, ...) are never read from input,
  `LIKE` wildcards are escaped, meeting links must be `https`.
- **Responses**: API Resources only (no raw models), other users' email/phone are never exposed, errors are JSON without stack traces.
- **AI**: provider key only in `.env`; no user or project data is sent to the provider; failures return a generic `503` and store nothing.

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

Install dependencies (needed once, even if PHP/Composer are not installed locally):

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html \
  laravelsail/php84-composer:latest composer install --ignore-platform-reqs
```

Start the stack and prepare the app. On Linux, macOS and WSL2 use Sail:

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate:fresh --seed
./vendor/bin/sail artisan storage:link
```

On Windows (Git Bash / PowerShell), where the Sail script does not run, use Docker Compose directly:

```bash
docker compose up -d
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate:fresh --seed
docker compose exec laravel.test php artisan storage:link
```

The API is then available at `http://localhost/api/v1` and emails (password reset) arrive in Mailpit at `http://localhost:8025`.
If port 80 is busy, set `APP_PORT` in `.env`.

In the `local` environment, `--seed` also loads **demo data** (see below). In any other environment only the four teams are seeded.

### Environment variables

| Variable | Purpose |
|---|---|
| `APP_URL`, `FRONTEND_URL` | API URL; frontend origin (used for CORS and the password reset link) |
| `DB_*` | MySQL connection (preconfigured for Sail) |
| `MAIL_*` | Mail transport (Mailpit in development) |
| `AI_BASE_URL`, `AI_API_KEY`, `AI_MODEL`, `AI_TIMEOUT` | Any OpenAI-compatible chat-completions provider. Without them the AI endpoints answer `503` |

Set `APP_DEBUG=false` outside local development.

### Running the tests

```bash
./vendor/bin/sail artisan test          # Windows: docker compose exec laravel.test php artisan test
./vendor/bin/sail bin pint --test       # code style
```

Tests run against a real MySQL `testing` database (reports use SQL date functions). AI calls are always faked.

## Demo accounts

Password for all accounts: `Password123`

| User | Email | Role in the demo |
|---|---|---|
| Mohamed Salah | `mohamed@collabspace.test` | Leader of **Alpha** (best account to explore) |
| Mohanad Wahib | `mohanad@collabspace.test` | Backend Engineer |
| Sara Hassan | `sara@collabspace.test` | UI Designer |
| Ali Mohamed | `ali@collabspace.test` | Frontend Developer, reviewer of some tasks |
| Khalid Mousa | `khalid@collabspace.test` | Backend Developer |
| Mahmoud Salah | `mahmoud@collabspace.test` | Marketing Lead |

The demo contains two projects (**Alpha**, active, and **SepetGo**, done), tasks in every status spread over several months, comments, files,
chat messages, upcoming meetings, a meeting with transcript and summary, notifications and a saved report.

## API documentation (Postman)

Import [`postman/Collabspace.postman_collection.json`](postman/Collabspace.postman_collection.json). It covers every endpoint, grouped by module.

1. The `baseUrl` collection variable is `http://localhost/api/v1` (change it if your port differs).
2. Run **Auth → Login (saves token)** with a demo account (set the `email` and `password` collection variables, e.g. `mohamed@collabspace.test` / `Password123`).
   The token is saved automatically and sent as a Bearer token by every other request.
   Or start fresh: **Register** creates a new user, **Register helper: second user** creates a teammate.
3. Ids such as `projectId`, `taskId`, `fileId`, `meetingId` are saved by the "create" requests, so you can run the folders in order.
4. File uploads need you to pick a file in the request body. The AI requests need the `AI_*` variables in `.env`.

## Endpoint overview

All routes live under `/api/v1`. Only `auth/register`, `auth/login`, `auth/forgot-password` and `auth/reset-password` are public.

| Area | Endpoints |
|---|---|
| Auth | `POST /auth/register`, `/auth/login`, `/auth/logout`, `/auth/forgot-password`, `/auth/reset-password` |
| Profile | `GET/PUT /me`, `POST /me/avatar`, `PUT /me/password`, `GET /me/stats`, `GET /users?search=` |
| Projects | `GET/POST /projects`, `GET/PUT/DELETE /projects/{project}`, members (`GET/POST/PUT/DELETE /projects/{project}/members…`), `GET /projects/{project}/teams`, `GET /teams` |
| Tasks | `GET/POST /projects/{project}/tasks`, `GET /tasks`, `GET/PUT/DELETE /tasks/{task}`, `PATCH /tasks/{task}/status` |
| Comments | `GET/POST /tasks/{task}/comments`, `PUT/DELETE /comments/{comment}` |
| Files | `GET/POST /projects/{project}/files`, `GET /files`, `GET /files/{file}/download`, `DELETE /files/{file}` |
| Chat | `GET /conversations`, `POST /conversations/direct`, `GET/POST /conversations/{conversation}/messages`, `GET /messages/{message}/attachment` |
| Meetings | `GET/POST /meetings`, `GET/PUT/DELETE /meetings/{meeting}`, `GET/POST /meetings/{meeting}/transcript`, `POST /meetings/{meeting}/summary/generate` |
| Notifications | `GET /notifications`, `POST /notifications/{id}/read`, `POST /notifications/read-all` |
| Insights | `GET /dashboard`, `GET /reports/overview`, `GET/POST /reports`, `GET /reports/{report}`, `GET /search?q=` |
| AI | `GET/POST/DELETE /ai/messages` |

## Decisions and limits (v1)

- **No realtime chat**: messages are REST with cursor pagination (clients poll). WebSockets would add infrastructure that the first version does not need.
- **Meetings store a link** (Meet, Zoom, Jitsi...) instead of hosting video. Transcript entries are stored as data; the summary is generated from them by the AI provider.
- **Email is read-only** in the profile: changing it safely needs a verification flow.
- Not included: Google sign-in (needs OAuth client credentials), chat threads and calls, file versioning, daily status updates, table/timeline task views.
  The full list is in `docs/SPEC.md` (section 7).
