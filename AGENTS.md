# Simora — Codex Engineering Instructions

## Project

Simora — Virtual Football Analytics.

## Source of Truth

Read `docs/architecture/SIMORA_BACKEND_BLUEPRINT.md` before planning or implementing backend features.

Also examine the existing source code, database reference schema, frontend views, tests, and module-specific documentation.

The blueprint defines the target architecture. Existing source code defines current behavior. Never assume an unimplemented feature is operational simply because it appears in documentation.

When documentation conflicts, identify the conflict, apply the agreed target architecture without breaking existing interfaces, and document the resolution.

## Engineering Standards

- PHP 8.1+ with a planned migration toward a supported PHP 8.x baseline.
- PSR-4 autoloading and PSR-12 formatting.
- SOLID, dependency injection, repository pattern, and service-oriented business logic.
- MySQL 8 with InnoDB, foreign keys, and versioned migrations.
- REST APIs with consistent JSON responses.
- Strong validation, authorization, transactions, and error handling.
- Environment-managed credentials and secure authentication.
- PHPUnit for backend tests.
- Maintain existing server-rendered PHP views, Tailwind CSS, and JavaScript.

## Non-Negotiable Rules

1. Preserve existing frontend design and working routes.
2. Never replace production logic with fabricated or synthetic prediction results.
3. Keep synthetic fixture data isolated from live records.
4. Implement all eight approved prediction markets consistently.
5. Ensure market-specific predictions independently rank eligible matches.
6. Never introduce database tables without defining their relationships and ownership.
7. Use immutable prediction publications and auditable settlement records.
8. Prevent future-data leakage in forecasting and backtesting.
9. Use database transactions for financially sensitive operations.
10. Keep Auto Trade disabled until external integrations, permissions, risk controls, and release requirements are satisfied.

## Implementation Workflow

For every implementation phase:

1. Read relevant blueprint sections.
2. Inspect affected source files.
3. Identify dependencies and existing functionality.
4. Produce a concise implementation plan.
5. Implement only the approved phase.
6. Write or update automated tests.
7. Run applicable tests and static checks.
8. Document modified files, migrations, and API changes.
9. Identify unresolved issues.
10. Update implementation progress.

Do not silently skip blueprint requirements or declare untested features complete.

## Completion Requirement

Each implementation must include:

- Summary of changes.
- Files created or modified.
- Database migrations added.
- API endpoints implemented.
- Tests executed and results.
- Remaining risks or dependencies.
- Recommended next implementation phase.
