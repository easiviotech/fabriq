# Contributing to Fabriq

Thank you for considering contributing to Fabriq! This guide will help you get started.

## Development Setup

### Prerequisites

- PHP 8.2+
- Swoole extension (`pecl install swoole`)
- Composer
- Redis (for queue/event/realtime features)
- MySQL 8.0+ (for ORM features)

### Getting Started

```bash
git clone https://github.com/easiviotech/fabriq.git
cd fabriq
composer install
```

### Running Tests

```bash
# Run the unit test suite
composer test

# Run static analysis
composer analyse
```

## Pull Request Process

### Branch Naming

Use descriptive branch names with a prefix:

- `feature/` — new features (e.g., `feature/rate-limit-headers`)
- `fix/` — bug fixes (e.g., `fix/tenant-cache-expiry`)
- `refactor/` — code refactoring
- `docs/` — documentation changes
- `test/` — adding or updating tests

### Before Submitting

1. **Tests pass**: Run `composer test` and ensure all tests pass
2. **PHPStan passes**: Run `composer analyse` with zero errors
3. **Code style**: Follow the existing code conventions (PSR-12)
4. **Focused changes**: Keep PRs small and focused on a single concern
5. **Documentation**: Update docs if your change affects the public API

### PR Description

Include in your PR:

- **What** changed and **why**
- Any breaking changes
- How to test the change
- Screenshots (for UI changes)

### Review Process

1. A maintainer will review your PR within a few days
2. Address any feedback
3. Once approved, a maintainer will merge it

## Coding Standards

### General

- Use `declare(strict_types=1)` in every PHP file
- Use `final` for classes that aren't designed for extension
- Type everything: parameters, return types, properties
- Avoid `mixed` unless truly necessary

### Architecture

- **No Swoole leakage**: Keep Swoole-specific code in the kernel and server layers. Business logic should be framework-agnostic where possible.
- **Testability**: Prefer dependency injection over static calls. New code should be testable without Swoole running.
- **Multi-tenancy**: Always consider tenant isolation. Database queries should scope by tenant_id.

### Tests

- Place tests in `tests/Unit/` (mirrors `packages/` structure)
- Test classes should be `final`
- Use descriptive test method names: `testDecodeExpiredTokenReturnsNull`
- Avoid mocking what you don't own — use test doubles for Redis/DB

## Reporting Issues

Use the issue templates on GitHub:

- **Bug Report**: Include reproduction steps, expected vs actual behavior
- **Feature Request**: Describe the use case and proposed solution

## Code of Conduct

Be respectful and constructive. We're all here to build great software together.

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
