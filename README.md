# Backend System PHP

A backend management system built with **Laravel 13, PHP, MySQL, and Docker**, designed to simulate a real-world internal enterprise management platform.

This project focuses on RESTful API development, database operations, authentication, authorization, and automated testing, with an emphasis on maintainability, scalability, and clean application architecture.

## Tech Stack

| Category | Technology |
|---|---|
| Backend | PHP 8.3 / Laravel 13 |
| Database | MySQL 8.4 |
| Web Server | Nginx |
| Containerization | Docker / Docker Compose |
| API | RESTful API |
| Authentication | Token-based Authentication |
| Testing | Laravel Feature Tests |
| Version Control | Git / GitHub |

## Project Architecture

The project uses Docker Compose to manage the local development environment, separating the Laravel application source code from the Docker infrastructure configuration.

```text
Backend_System_php/
├── docker/
│   ├── nginx/
│   │   └── default.conf       # Nginx Configuration
│   └── php/
│       └── Dockerfile        # PHP Container Configuration
├── src/                      # Laravel Application
│   ├── app/                  # Application Logic
│   ├── bootstrap/            # Application Bootstrap
│   ├── config/               # Application Configuration
│   ├── database/             # Migrations / Seeders
│   ├── routes/               # Route Definitions
│   └── tests/                # Automated Tests
├── .gitignore
├── docker-compose.yml        # Docker Compose Services
└── README.md                 # Project Documentation
```

### Application Design

The Laravel application follows a separation-of-concerns approach, with responsibilities organized across framework components.

- **Controller**: Handles incoming HTTP requests and returns API responses.
- **Form Request**: Validates incoming API request data.
- **Middleware**: Handles authentication and access control.
- **Model**: Manages database interactions using Eloquent ORM.
- **Migration**: Defines and maintains database schemas.
- **Feature Tests**: Verify API behavior, validation rules, and expected responses.

## Features

### Implemented

- [x] Laravel project initialization
- [x] Docker-based development environment
- [x] MySQL database integration
- [x] Administrator account database structure
- [x] Administrator login API
- [x] Token-based authentication
- [x] Request validation
- [x] Automated feature tests
- [x] Administrator List, Detail, Create, and Update APIs
- [x] Shared administrator token and active-account middleware
- [x] MVP RBAC schema, permission queries, and permission middleware

### Planned

- [ ] Delete Administrator API
- [ ] Administrator role assignment API
- [ ] Role permission management API
- [ ] API activity logging
- [ ] Redis caching integration
- [ ] OpenAPI specification and interactive API documentation
- [ ] CI/CD pipeline

## Administrator APIs

The following endpoints are implemented. Administrator management endpoints require middleware in this order: `auth:sanctum`, `admin.token`, `admin.active`, and `admin.permission:<permission>`.

| Method | Endpoint | Permission |
|---|---|---|
| POST | `/api/v1/admin/login` | Public; credentials and active status required |
| GET | `/api/v1/admin/me` | Administrator Bearer Token and active account; no RBAC permission |
| POST | `/api/v1/admin/logout` | Administrator Bearer Token; disabled accounts may log out |
| GET | `/api/v1/admin/admins` | `admins.read` |
| GET | `/api/v1/admin/admins/{id}` | `admins.read` |
| POST | `/api/v1/admin/admins` | `admins.create` |
| PUT | `/api/v1/admin/admins/{id}` | `admins.update` |

`DELETE /api/v1/admin/admins/{id}` is **not implemented**. Role assignment and permission management APIs are also **not implemented**.

### List and Detail

The list supports `page`, `per_page` (default 20, maximum 100), `search` across username/name, and `is_active` filtering. Sorting accepts `id`, `username`, `name`, `created_at`, or `updated_at`, with `asc` or `desc`; the default is `id desc`. Non-unique sort fields use `id` as a tie-breaker. Search treats `%` and `_` as literal characters.

Lists return `data`, `links`, and `meta`. Detail responses return `data`. Both return HTTP 200 and expose only `id`, `username`, `name`, `is_active`, `created_at`, and `updated_at`. Invalid or missing detail IDs return HTTP 404 without internal model names.

### Create and Update

Create requires `username`, `name`, `password`, `password_confirmation`, and `is_active`, and returns HTTP 201 with `data` and a `Location` header. PUT requires all three basic fields (`username`, `name`, `is_active`); password is optional and remains unchanged when omitted. Updates return HTTP 200 with `data`.

Usernames must be unique, at most 50 characters, and contain only ASCII letters, digits, `.`, `_`, or `-`. Names are trimmed, must not be blank, and are limited to 100 characters. Supplied passwords require confirmation, at least 12 characters, no null bytes, and no more than 72 bytes. Password hashing uses the existing Model cast. `is_active` is required and validated as boolean.

Create/update reject fields outside their input allowlist, including role and permission fields. New administrators receive no roles or tokens automatically. Updating your own account through the management API returns HTTP 403. Validation failures, including username conflicts, return HTTP 422. Authentication failures return HTTP 401; disabled accounts or missing permissions return HTTP 403. Error responses use `message`, with `errors` for validation failures. Passwords and tokens are not included in management responses.

Disabling an account preserves its existing tokens but blocks protected management access. Logout revokes only the current token and remains available to disabled administrators.

## MVP RBAC

RBAC uses exactly three core tables; existing Sanctum and framework tables remain separate:

| Table | Purpose and constraints |
|---|---|
| `admins` | Existing administrator identity and active status |
| `admin_roles` | `admin_id`, `role`; unique `(admin_id, role)`; foreign key to `admins.id` with delete cascade |
| `roles_permissions` | `role`, `permission`; unique `(role, permission)` |

Roles and permissions are string codes, with no standalone role or permission definition tables and no third-party permission package. Both role columns use `VARCHAR(50)` with `utf8mb4_unicode_ci`; permissions use `VARCHAR(100)`. The two mapping tables have no surrogate IDs or timestamps.

`Admin::hasPermission()` joins the mapping tables and uses `exists()` to evaluate the union of all assigned roles. Permissions are queried directly without Redis or token snapshots, so changes apply on the next query. `admin.permission` accepts one valid lowercase permission code and returns HTTP 403 when it is invalid or not granted.

String codes have no foreign-key-backed definition registry: spelling, case conventions, renaming, and allowed codes require application-level discipline. Permission checks are implemented, but APIs for assigning roles or modifying role permissions are not available. `admins.create` does not grant role-assignment authority; `admins.update` is sensitive because it can change another account's password and active status.

## Development Environment

The local development environment is managed using Docker Compose.

### Start Containers

Build and start the required services:

```bash
docker compose up -d --build
```

### Check Container Status

Verify that the containers are running:

```bash
docker compose ps
```

### Stop Containers

Stop and remove the project containers:

```bash
docker compose down
```

## Testing

The project uses Laravel's built-in testing tools to validate application functionality and API behavior.

Before running database-writing tests, verify the effective environment is `testing`, the connection is MySQL at `mysql:3306`, `SELECT DATABASE()` returns `laravel_testing`, and configuration is not cached. The test database, required schema, and permissions must already exist. Do not substitute the development database or remove the protections in `src/phpunit.xml` and `src/tests/TestCase.php`.

Run the test suite from the repository root using the existing PHP container:

```bash
docker compose exec php php artisan test
```

Feature tests cover authentication, token revocation and device isolation, active-account enforcement, RBAC queries/middleware, and administrator List, Detail, Create, and Update behavior. Documentation-only changes do not require running application tests.

## Development Goals

The primary goals of this project are:

- Build maintainable and extensible RESTful APIs.
- Implement common features found in enterprise internal management systems.
- Establish a consistent development environment using Docker.
- Improve software reliability through automated testing.
- Continuously enhance application architecture, code quality, and development workflows.

## Project Status

**Under Development**

This project is actively being developed. Features, architecture, and technical documentation will be updated as development progresses.
