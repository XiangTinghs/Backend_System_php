# Testing and Verification

## Testing

The project uses Laravel's built-in testing tools to validate application functionality and API behavior.

Before running database-writing tests, verify the effective environment is `testing`, the connection is MySQL at `mysql:3306`, `SELECT DATABASE()` returns `laravel_testing`, and configuration is not cached. The test database, required schema, and permissions must already exist. Do not substitute the development database or remove the protections in `src/phpunit.xml` and `src/tests/TestCase.php`.

Run the test suite from the repository root using the existing PHP container:

```bash
docker compose exec php php artisan test
```

Feature tests cover authentication, token revocation and device isolation, active-account enforcement, RBAC queries/middleware, administrator List, Detail, Create, and Update, and role listing/assignment/revocation, including error precedence and delegation restrictions. Audit tests cover snapshot filtering, business/audit rollback, query filters, UTC boundaries and serialization, pagination limits, and access control. On 2026-11-04, the Platform Model selection passed 18 tests with 64 assertions, the Issue #7 and affected audit selection passed 189 tests with 944 assertions, and the broader regression selection passed 487 tests with 1965 assertions, zero failures, and no risky tests. These selections overlap and must not be added together. Documentation-only changes do not require running application tests. If migrations are prohibited for a verification run, the default `RefreshDatabase` lifecycle must not be allowed to rebuild the schema; use an explicitly verified existing-schema execution approach and report it.

### Issue #7 Verification Scope (2026-11-04)

Verification used the existing PHP 8.3 container and MySQL `mysql:3306/laravel_testing`. A separate read-only preflight process rejected cached configuration and verified the effective testing environment, actual `SELECT DATABASE()`, UTC Session timezone, required columns, case-sensitive collations, unique indexes, and player FK RESTRICT before PHPUnit started. Successful transactional tests also exercised write permissions.

A temporary runner outside the repository set `RefreshDatabaseState::$migrated = true` only after the preflight succeeded. This retained the existing tests' transaction/rollback lifecycle while avoiding implicit `migrate:fresh`; neither `phpunit.xml`, `tests/TestCase.php`, nor `.env` was changed. Running the default test command without this verified existing-schema setup can invoke RefreshDatabase migrations.

The regression selection excluded `UserModelTest::test_conversion_and_rollback_refuse_existing_rows_without_modifying_them` via a negative PHPUnit filter. Static analysis shows its non-empty-users guard should throw before DDL, but it remains explicitly excluded by user instruction. It was not executed. Its previously authorized actual-database guard remains in place. No migration or schema rebuild was executed, and no development/production database was modified.

Validation after Model hardening: 18 Platform Model tests / 64 assertions passed; 189 Feature/Audit tests / 944 assertions passed; 487 regression tests / 1965 assertions passed (including those selections), zero failures and one case excluded. Model tests cover creation, update(), fill()->save(), direct attribute assignment followed by save(), quiet updates/saves, case-only changes, unchanged persisted data on rejection, and normal name/status updates. PSR-12 check-only validation passed for all 16 changed/new PHP files, and `git diff --check` passed. Real dual-connection races, external integration behavior, and database-level code immutability are not verified guarantees.

