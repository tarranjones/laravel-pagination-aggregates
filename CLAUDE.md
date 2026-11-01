<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.


## Git & PR Workflow

- All non-trivial changes go through a PR — never commit directly to main.
- Branch naming: `feat/`, `fix/`, `chore/`, `refactor/` prefixes.
- Before creating a PR: run `vendor/bin/pest --compact`, `vendor/bin/pint --test`, `vendor/bin/rector --dry-run`, and `vendor/bin/phpstan analyse` (or `composer check`). All must pass.
- Create PRs with `gh pr create`. CI runs PHP 8.4/8.5 × Laravel 12/13 (plus non-blocking PHP 8.6) on SQLite, MySQL, MariaDB and PostgreSQL, plus Pint, Rector, and PHPStan. Tests must stay driver-agnostic (no hard-coded identifier quoting).
- After CI passes and the PR is reviewed, merge with `gh pr merge <number> --merge`, then `git checkout main && git pull`.
- Do not add `Co-Authored-By: Claude` lines to commit messages.

</laravel-boost-guidelines>
