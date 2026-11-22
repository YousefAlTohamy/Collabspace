# Figma coverage: from screens to API

How each screen of the Collabspace design (Team C) is served by this API, and what was left out on purpose.
Legend: ✅ covered · ⚠️ covered with a note · ❌ not in v1 (reason given).

All paths are under `/api/v1`. Business rules (R1–R14) are in [`SPEC.md`](SPEC.md).

## What the design asks for

The case study describes a workspace for remote teams where tasks, files, chat and meetings live in one place.
Two users drive the design: the **team member** (needs his tasks, files and conversations in one place) and the **project leader**
(needs progress, deadlines and a clear view of who does what). The API turns that into one rule: **the project is the permission boundary**.
Members see and change what belongs to their projects; leaders manage the project and its members.

## Authentication

| Screen / element | API | Status |
|---|---|---|
| Sign in (email, password, remember me) | `POST /auth/login` (`remember` → 30-day token) | ✅ |
| Forgot password | `POST /auth/forgot-password`, `POST /auth/reset-password` (link points to the frontend) | ✅ |
| Sign up (full name, email, password) | `POST /auth/register` | ✅ |
| Sign in / up with Google | — | ❌ needs OAuth client credentials; email + password covers the flow |
| Language selector | client side | ❌ no API needed |

## Dashboard

| Element | API | Status |
|---|---|---|
| Pending / In progress / Completed / Completion rate | `GET /dashboard` → `tasks` | ✅ |
| "+9% from last month" on each card | `tasks.completed_this_month` / `completed_last_month` | ⚠️ only the completed counter has a monthly comparison; task statuses have no history |
| Project overview chart (+ "All projects" / "This year") | `GET /dashboard` → `project_overview`; with filters `GET /reports/overview?project_id=&period=` | ✅ |
| Active projects with progress | `GET /dashboard` → `active_projects` | ✅ |
| Recent files + download | `GET /dashboard` → `recent_files`, `GET /files/{file}/download` | ✅ |
| My tasks (due, priority, progress) | `GET /dashboard` → `my_tasks` | ✅ |
| Teams widget (member tasks 12/14) | `GET /projects/{project}/teams` | ✅ |
| Upcoming meetings | `GET /dashboard` → `upcoming_meetings` | ✅ |
| Ask AI | `POST /ai/messages` | ✅ |
| Notifications bell | `GET /notifications`, `POST /notifications/{id}/read`, `POST /notifications/read-all` | ✅ |
| Global search + recent searches | `GET /search?q=` | ✅ search · ❌ recent searches are kept by the client |

## Projects

| Screen / element | API | Status |
|---|---|---|
| All projects (search, filter, sort, columns) | `GET /projects?search=&status=&sort=` | ✅ files count, members, priority, status included |
| Add project (name, priority, guests, dates) | `POST /projects` with `members[]`; guest search `GET /users?search=` | ✅ |
| Add project files (in the same overlay) | `POST /projects/{project}/files` | ⚠️ second call after the project is created |
| Project overview (description, due-date bar, progress gauge, counters, details) | `GET /projects/{project}` | ✅ `progress_percent` is computed from tasks |
| Overview team members | `GET /projects/{project}/members` | ✅ |
| Tasks tab (Kanban) | `GET /projects/{project}/tasks` | ✅ |
| Teams tab (UI/UX, Frontend, Backend, Marketing) | `GET /projects/{project}/teams` | ✅ |
| Files tab (recent + all, upload) | `GET /projects/{project}/files`, `POST /projects/{project}/files` | ✅ |
| Edit / delete / mark done | `PUT /projects/{project}`, `DELETE /projects/{project}` | ✅ leaders only; "done" requires all tasks completed (R5) |
| Manage members | `POST/PUT/DELETE /projects/{project}/members…` | ✅ the last leader cannot be removed (R1) |

## Tasks

| Screen / element | API | Status |
|---|---|---|
| Kanban columns To-do / In progress / In review / Completed | `status` field, `PATCH /tasks/{task}/status` | ✅ the review step is enforced (R4) |
| Add task inside a column | `POST /projects/{project}/tasks` with `status` | ✅ |
| Create task overlay (name, dates, guests, files) | same endpoint with `assignee_ids[]`; files via `POST /projects/{project}/files` with `task_id` | ✅ |
| Reviewer, priority, progress bar, comments counter, due date | `reviewer_id`, `priority`, `progress`, `comments_count`, `due_date` (+ `is_overdue`) | ✅ |
| Tasks page: project filter, "My tasks" | `GET /tasks?project_id=&assigned_to_me=&status=&search=` | ✅ |
| "UI/UX tasks", "Frontend tasks" … filters | — | ❌ no filter by the assignee's team; `assignee_id` covers the common case |
| Task comments | `GET/POST /tasks/{task}/comments`, `PUT/DELETE /comments/{comment}` | ✅ |

## Chats

| Screen / element | API | Status |
|---|---|---|
| Inbox (direct messages) | `GET /conversations`, `POST /conversations/direct` | ✅ only between users who share a project |
| Project chats (public channel) | one channel per project in `GET /conversations` | ✅ members only |
| Team channels (Frontend, Backend…) | `type: team` entries in `GET /conversations` | ✅ one channel per team in each project, open to the members of that team |
| Messages, attachments | `GET/POST /conversations/{conversation}/messages`, `GET /messages/{message}/attachment` | ✅ same upload rules as project files |
| Threads (#Screen edit), voice/video call icons, search in chat | — | ❌ out of scope; no realtime in v1 (clients poll with cursor pagination) |

## Meetings

| Screen / element | API | Status |
|---|---|---|
| Weekly calendar | `GET /meetings?from=&to=` | ✅ |
| Add meeting (subject, date, time, duration, guests, note) | `POST /meetings` (+ optional `project_id`, `meeting_url`) | ✅ |
| Join meeting | `GET /meetings/{meeting}` returns `meeting_url` | ⚠️ the API stores a link (Meet, Zoom, Jitsi); it does not host video |
| Transcript + search | `GET/POST /meetings/{meeting}/transcript?q=` | ✅ |
| AI summary | `POST /meetings/{meeting}/summary/generate` | ✅ needs the AI provider variables |
| Note tab | `note` field | ✅ |
| Comments tab, transcript language selector | — | ❌ not in v1 |
| Meeting reminder notification | scheduled command `notifications:send-reminders` | ✅ 30 minutes before, once per meeting |

## Reports

| Element | API | Status |
|---|---|---|
| Task completion / projects completion / teams activity rates (+ change vs last month) | `GET /reports/overview` | ✅ computed from task timestamps for explicit windows |
| Task completion time vs deadline, projects progress, worker activity charts | same response (monthly series) | ✅ |
| Duration filter (today / week / month / 3 months / year) | `period=week\|month\|three_months\|year` | ⚠️ "today" is not offered |
| Submit a detailed report | `POST /reports`, `GET /reports`, `GET /reports/{report}` | ✅ stores the computed numbers as a snapshot |
| AI button on the report form | — | ❌ reports are numeric snapshots in v1 |

## Profile and settings

| Element | API | Status |
|---|---|---|
| Name, job title, joined date, about | `GET /me`, `PUT /me` | ✅ |
| Completed tasks counter | `GET /me/stats` | ✅ |
| Hours worked, recent activities | — | ❌ the system does not track time or an activity log |
| Tasks tab (To do / Done) | `GET /tasks?assigned_to_me=1&status=` | ✅ |
| Files tab | `GET /files` | ⚠️ all files of my projects (no "uploaded by me" filter) |
| Settings: phone, availability, years of experience, avatar | `PUT /me`, `POST /me/avatar` | ✅ |
| Settings: email | shown, read-only | ⚠️ changing it safely needs a verification flow |
| Settings: current team / project | derived from `GET /projects` | ⚠️ not a stored field |
| Change password | `PUT /me/password` | ✅ revokes the other tokens |

## Files page

| Element | API | Status |
|---|---|---|
| Recent files / all files, search, project filter | `GET /files?project_id=&search=` | ✅ |
| Upload, download | `POST /projects/{project}/files`, `GET /files/{file}/download` | ✅ private storage, strict type and size checks |

## Overlays

| Overlay | API |
|---|---|
| Teams filter (UI/UX, Frontend, Backend, Graphic designers) | `GET /teams` (seeded: UI/UX, Frontend, Backend, Marketing; the design's "Graphic designers" is treated as part of UI/UX) |
| Project filter | `GET /projects` |
| Notifications (meeting reminder, task overdue, team update) | `GET /notifications` (project joined, task assigned, meeting invitation, meeting reminder, task overdue) |
| Recent searches, language | client side |

## Case-study ideas left for later

Daily status updates, table/timeline task views, workflows, invitations by email, file versioning, AI root-cause analysis.
They have no screen in the design, and the scope was kept to what a team can use on day one.

## Notes on the design itself

- Chart axes and many names are placeholders (for example every member called "Mohanad wahib"); the API returns real computed values.
- The Add meeting overlay repeats the "Meeting subject" placeholder in the date, time and duration fields; the API takes `starts_at` and `duration_minutes`.
- Cards show reviewer and priority while the Create task overlay does not; both are optional fields in the API.
