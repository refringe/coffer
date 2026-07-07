# Contributing

Thanks for your interest in improving Coffer! Please discuss significant changes (via an issue) before starting work to ensure they align with the project direction. If you propose a new feature, please be willing to implement at least some of the code needed to complete it.

## Which Branch?

The `main` branch holds the latest stable release. The `develop` branch holds the latest development changes. All feature branches should be based on `develop`, and all pull requests should target `develop`.

## Pull Request Guidelines

- **Keep them small.** Limit a PR to a single bug fix or feature. This makes review and merging easier.
- **Perform a self-review.** Review your own changes before submitting to catch mistakes.
- **Remove noise.** Avoid unrelated whitespace, formatting, or text changes that aren't part of the PR's intent.
- **Create a meaningful title.** The title should clearly describe the change.
- **Write detailed commit messages.** Explain the what and the why.

## Style Guide

Coffer follows the PSR-12 coding standard and PSR-4 autoloading. Code style is enforced automatically with [Laravel Pint](https://laravel.com/docs/pint); configure your IDE to format with Pint on save, or run it manually:

```bash
composer pint
```

### Tests

Changes are verified automatically on every pull request. If you add a feature or fix a bug, add or update [Pest](https://pestphp.com) tests to cover it. Type coverage must remain at 100%.

### Before Submitting

Run `composer sendit` to execute all quality checks in sequence — formatting, linting (Rector, PHPStan, type coverage, spell check), and the full test suite:

```bash
composer sendit
```
