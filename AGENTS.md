# Repository Guidelines

## Project Structure & Module Organization
ASI PROCURA is a Laravel 12 procurement application using PHP 8.2+, Blade, Bootstrap, and MySQL/MariaDB.
- `app/Http/` contains controllers, validation requests, and role/workspace middleware; `app/Policies/` defines authorization.
- `app/Models/`, `app/Enums/`, `app/Services/`, and `app/Support/` contain domain logic. Route state changes through `PurchaseRequestWorkflow` and `PurchaseRequestStatus` to preserve audit trails.
- `routes/web.php` defines web routes. Views and frontend sources live in `resources/views/`, `resources/js/`, and `resources/css/`; public assets live in `public/`.
- `database/` contains migrations, factories, and seeders. Tests live in `tests/Feature/` and `tests/Unit/`.
- `ARCHITECTURE/` documents the system; `infra/` contains Docker and PowerShell deployment tooling.

## Build, Test, and Development Commands
Run commands from the repository root:
- `composer install` and `npm install`: install dependencies.
- `php artisan migrate`: apply migrations to the configured database.
- `composer dev`: run Laravel, the queue listener, logs, and Vite together.
- `php artisan serve`: run only the local HTTP server.
- `npm run build`: build frontend assets; `npm run dev`: start Vite.
- `composer test`: clear cached configuration and run PHPUnit.
- `php artisan test --filter=WorkflowSubmissionPopupTest`: run focused tests.
- `vendor/bin/pint --dirty`: format changed PHP files.

On XAMPP, substitute `& "C:\xampp\php\php.exe" artisan ...` when PHP is absent from PATH. Use `php composer.phar` if Composer is unavailable globally.

## Coding Style & Naming Conventions
Follow `.editorconfig`: UTF-8, LF, four-space indentation, and final newlines; YAML generally uses two spaces. Follow Laravel/Pint conventions. Use PascalCase classes, camelCase methods, snake_case database columns, timestamped migration filenames, and descriptive named routes. Match neighboring Blade and JavaScript code.

## Testing Guidelines
Use PHPUnit 11. Name files `*Test.php` and methods `test_descriptive_behavior`. Database feature tests use `RefreshDatabase`; `phpunit.xml` configures in-memory SQLite and isolated mail/session/cache drivers. Cover permissions, state transitions, signatures, duplicate submissions, and embedded redirects when changing workflows. No numeric coverage threshold is configured.

## Commit & Pull Request Guidelines
Recent commits use imperative, descriptive subjects, such as `Fix HTTPS workflow redirects behind tunnel`; no mandatory prefix is evident. Keep commits focused. PRs should explain the problem, resulting behavior, validation, and deployment/migration requirements. Link relevant issues and include screenshots for UI changes.

## Security & Configuration
Never commit `.env`, credentials, or production exports. Preserve server keys and uploads during deployment. For HTTPS tunnels, configure the public HTTPS `APP_URL` without `/login` and preserve secure redirects. Never run destructive database resets or demo seeders against production without explicit authorization and a backup.
