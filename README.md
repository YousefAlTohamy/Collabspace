# Collabspace API

Laravel 13 API for the Collabspace team collaboration platform, running on Docker via
Laravel Sail (MySQL 8 + Mailpit). Requires Docker.

## Run locally

```bash
git clone <repo-url> collabspace && cd collabspace
cp .env.example .env

./vendor/bin/sail up -d

./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan storage:link
```

The API is now at `http://localhost:8000/api/v1`. Mailpit (email catcher) is at
`http://localhost:8025`.

## Tests

The test suite runs against the `testing` MySQL database (created automatically the
first time the MySQL container starts):

```bash
./vendor/bin/sail artisan test
```

## Code style

```bash
./vendor/bin/sail bin pint
```
