# Project Architecture

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

