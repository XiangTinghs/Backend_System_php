# Backend System PHP

A backend management system built with **Laravel 13, PHP, MySQL, and Docker**, designed to simulate a real-world internal enterprise management platform.

This project focuses on RESTful API development, database operations, authentication, authorization, and automated testing, with an emphasis on maintainability, scalability, and clean application architecture.

## Tech Stack

| Category | Technology |
|---|---|
| Backend | PHP / Laravel 13 |
| Database | MySQL |
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

### Planned

- [ ] Administrator account management
- [ ] Role-Based Access Control (RBAC)
- [ ] API activity logging
- [ ] Redis caching integration
- [ ] API documentation
- [ ] CI/CD pipeline

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

Run the test suite from the Laravel application environment:

```bash
php artisan test
```

The automated tests primarily cover request validation, administrator login, authentication, and expected API responses.

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
