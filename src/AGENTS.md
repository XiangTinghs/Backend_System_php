# Laravel Application Guidelines

## Scope
- This file applies to `src/` and all its descendants, including application code, configuration, migrations, and tests.
- Follow `../AGENTS.md` for repository-wide rules: Laravel 13, PHP 8.3, PSR-12, SOLID, RESTful APIs, database confirmation, test isolation, Sanctum behavior, and Git approval. This file supplements those rules without weakening them.

## Application Conventions
- Follow the existing Laravel 13 structure: route and exception setup in `bootstrap/app.php`, API routes in `routes/api.php`, and versioned controllers under `app/Http/Controllers/Api/V1/`.
- Use Form Requests for validation and Eloquent for persistence. Keep controllers focused and avoid unnecessary abstractions.
- Preserve API contracts during unrelated work. Response format or behavior changes must update affected tests, including `HealthCheckTest` exact JSON assertions.
- Consult the root Sanctum section for implemented behavior and future work. An `admin` session guard configuration does not itself enforce administrator identity on `auth:sanctum` routes.

## Environment and Commands
- Use the Docker PHP 8.3 service. From the repository root, use `docker compose exec php php artisan ...` or `docker compose exec php composer ...` only when appropriate for the authorized task.
- Do not automatically install host PHP/Composer, install Boost, change dependencies, or regenerate instructions. These are not prerequisites for code review or document analysis.
- Analysis-only work is read-only. Documentation-only changes require neither new/updated tests nor execution of the application test suite.

## Database and Test Safety
- Before manual data-clearing migrations, database rebuilds, deletion, or volume removal, follow the root rule requiring explicit confirmation of the concrete operation and target.
- Preserve `phpunit.xml` isolation and `tests/TestCase.php` safety checks. Never weaken or bypass them to run tests in another environment or database.
- Database-writing tests must use the verified `testing` environment and dedicated MySQL `laravel_testing` database on `mysql:3306`. Existing `RefreshDatabase` behavior is permitted only within this verified scope when test execution is authorized.
- Resolve uncertainty about configuration, database availability, or permissions before running database-writing tests. Never substitute the development database.
- Functionality or behavior changes require appropriate test updates and relevant test execution when available; report verification limits. Preserve real-token revocation coverage for authentication/logout changes.

## Platform Management

- Follow the root platform contracts: creation-only code, name-only PUT, status-only PATCH, explicit field allowlists, and existing case-sensitive unique index behavior. Do not add platform deletion or implicit permission grants.
- Preserve the Platform Model update persistence guard: code changes (including case-only and quiet saves) throw LogicException; creation and normal name/status updates remain supported. Query Builder/bulk updates and direct SQL are outside its protection.
- Preserve same-connection transactional platform audit writes and matching service/Resource code snapshot filtering. Future external authentication and wallet behavior are outside this scope.
