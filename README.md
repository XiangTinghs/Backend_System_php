# Backend System PHP

A Laravel-based backend management system for game platform administration and player management.

The project focuses on RESTful API development, authentication, role-based access control, database consistency, and automated testing.

## Tech Stack

| Category | Technology |
|---|---|
| Backend | PHP 8.3 / Laravel 13 |
| Database | MySQL 8.4 |
| Web Server | Nginx |
| Containerization | Docker / Docker Compose |
| API | RESTful API |
| Authentication | Laravel Sanctum (Admin), RS256 JWT (Platform) |
| ORM | Eloquent ORM |
| Testing | Laravel Feature Tests |
| Version Control | Git / GitHub |

## Completed Features

- [x] Docker-based Laravel development environment
- [x] MySQL database integration
- [x] Administrator authentication (Login / Logout / Profile)
- [x] Administrator account management
- [x] Role-based access control (RBAC)
- [x] Administrator role assignment and revocation
- [x] Administrator audit logging and permission-protected audit log querying
- [x] Platform database schema and model relationships
- [x] Platform management APIs
- [x] Administrator player management APIs
- [x] Platform JWT verification and administrator public-key lifecycle APIs ([contract](docs/platform-jwt-authentication.md), [controlled permission operations](docs/platform-key-permission-operations.md))
- [x] JWT-protected Player Resolve API with persistent idempotency ([contract](docs/player-resolve-api.md))
- [x] Per-platform Resolve rate limits and controlled expired-idempotency cleanup ([operations](docs/platform-api-operations.md))
- [x] Automated feature testing

## Planned Features

- [ ] Secure platform-to-game API integration
- [ ] Player login and session management
- [ ] Transfer wallet operations
- [ ] Game transaction and balance tracking
- [ ] Player logout and balance settlement
- [ ] Administrator deletion and role/permission management
- [ ] Redis caching integration
- [ ] OpenAPI documentation
- [ ] CI/CD pipeline
