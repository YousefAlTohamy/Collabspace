# Data model (ERD)

Rendered by GitHub (Mermaid). Column lists show the keys and the fields that matter for the business rules;
the full definition is in [`SPEC.md`](SPEC.md) §4 and in `database/migrations`.

```mermaid
erDiagram
    USERS ||--o{ PROJECTS : "creates"
    USERS ||--o{ PROJECT_MEMBERS : "belongs to"
    PROJECTS ||--o{ PROJECT_MEMBERS : "has"
    TEAMS |o--o{ PROJECT_MEMBERS : "groups"

    PROJECTS ||--o{ TASKS : "contains"
    USERS ||--o{ TASKS : "creates"
    USERS |o--o{ TASKS : "reviews"
    TASKS ||--o{ TASK_ASSIGNEES : "assigned through"
    USERS ||--o{ TASK_ASSIGNEES : "works on"
    TASKS ||--o{ TASK_COMMENTS : "discussed in"
    USERS ||--o{ TASK_COMMENTS : "writes"

    PROJECTS ||--o{ FILES : "stores"
    TASKS |o--o{ FILES : "attached to"
    USERS ||--o{ FILES : "uploads"

    PROJECTS |o--o| CONVERSATIONS : "has one channel"
    USERS |o--o{ CONVERSATIONS : "direct chat (two users)"
    CONVERSATIONS ||--o{ MESSAGES : "contains"
    USERS ||--o{ MESSAGES : "sends"

    PROJECTS |o--o{ MEETINGS : "hosts"
    USERS ||--o{ MEETINGS : "organizes"
    MEETINGS ||--o{ MEETING_PARTICIPANTS : "invites"
    USERS ||--o{ MEETING_PARTICIPANTS : "attends"
    MEETINGS ||--o{ MEETING_TRANSCRIPTS : "records"
    USERS |o--o{ MEETING_TRANSCRIPTS : "speaks"

    USERS ||--o{ REPORTS : "submits"
    PROJECTS |o--o{ REPORTS : "scopes"
    USERS ||--o{ AI_MESSAGES : "chats with the assistant"
    USERS ||--o{ NOTIFICATIONS : "receives"

    USERS {
        bigint id PK
        string email UK
        string password
        string name
        string availability_status
        string avatar_path
    }
    TEAMS {
        bigint id PK
        string name UK
    }
    PROJECTS {
        bigint id PK
        string name
        string priority
        string status
        date start_date
        date end_date
        bigint created_by FK
        timestamp completed_at
    }
    PROJECT_MEMBERS {
        bigint id PK
        bigint project_id FK
        bigint user_id FK
        string role "leader or member"
        bigint team_id FK
        string job_title
    }
    TASKS {
        bigint id PK
        bigint project_id FK
        string title
        string status "todo, in_progress, in_review, completed"
        string priority
        tinyint progress
        date due_date
        bigint reviewer_id FK
        bigint created_by FK
        timestamp completed_at
        timestamp overdue_notified_at
    }
    TASK_ASSIGNEES {
        bigint task_id FK
        bigint user_id FK
    }
    TASK_COMMENTS {
        bigint id PK
        bigint task_id FK
        bigint user_id FK
        text body
    }
    FILES {
        bigint id PK
        bigint project_id FK
        bigint task_id FK
        bigint uploaded_by FK
        string original_name
        string path "private disk, never exposed"
        string mime_type
        bigint size
    }
    CONVERSATIONS {
        bigint id PK
        string type "project or direct"
        bigint project_id FK
        bigint user_one_id FK
        bigint user_two_id FK
    }
    MESSAGES {
        bigint id PK
        bigint conversation_id FK
        bigint user_id FK
        text body
        string attachment_path "private disk, never exposed"
    }
    MEETINGS {
        bigint id PK
        bigint project_id FK
        bigint created_by FK
        string title
        datetime starts_at
        smallint duration_minutes
        string meeting_url "https only"
        text summary
        timestamp reminder_sent_at
    }
    MEETING_PARTICIPANTS {
        bigint meeting_id FK
        bigint user_id FK
    }
    MEETING_TRANSCRIPTS {
        bigint id PK
        bigint meeting_id FK
        bigint speaker_id FK
        int offset_seconds
        text text
    }
    REPORTS {
        bigint id PK
        bigint user_id FK
        bigint project_id FK
        string type
        date start_date
        date end_date
        json snapshot
    }
    AI_MESSAGES {
        bigint id PK
        bigint user_id FK
        string role "user or assistant"
        text content
    }
    NOTIFICATIONS {
        uuid id PK
        string type
        bigint notifiable_id "user"
        json data
        timestamp read_at
    }
```

## Invariants the schema and code protect

| Invariant | Where it is enforced |
|---|---|
| A user appears once per project | `unique(project_id, user_id)` on `project_members` |
| A project always has a leader (R1) | `AddProjectMember` / `UpdateProjectMember` / `RemoveProjectMember` guard |
| Assignees and reviewers belong to the task's project (R3) | request validation + row locks in `LockTaskMembers` |
| A project has exactly one channel (R8) | `unique(project_id)` on `conversations`, created in `CreateProject` |
| One direct chat per pair of users (R8) | lower user id stored first + `unique(user_one_id, user_two_id)` |
| A user is invited once to a meeting / assigned once to a task | `unique` pairs on the pivot tables |
| Stored files are never reachable by path | private disk, random names, authorized download endpoints |
| Deleting a project removes everything under it | foreign keys with `cascadeOnDelete` + storage cleanup in `DeleteProject` |
