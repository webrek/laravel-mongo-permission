# Contributing

Thank you for taking the time to contribute.

## Getting started

```bash
git clone https://github.com/webrek/laravel-mongo-permission
cd laravel-mongo-permission
composer install
```

You need the PHP `mongodb` extension and a running MongoDB instance. Tests
connect using these environment variables (shown with their default values):

```bash
export MONGO_DB_HOST=127.0.0.1
export MONGO_DB_PORT=27017
export MONGO_DB_DATABASE=permission_test
```

## Before opening a pull request

CI runs tests across the supported PHP (8.2–8.5) and Laravel (12 and 13)
combinations, along with static analysis and mutation testing. Locally:

```bash
vendor/bin/phpunit                                        # tests
vendor/bin/phpstan analyse --memory-limit=1G              # static analysis (level 5)
vendor/bin/infection --threads=max                        # mutation testing
```

## Guidelines

- Keep pull requests focused: one logical change per PR.
- Add or update tests for behavior changes. Bug fixes should include a test
  that fails before the fix.
- Keep PHPStan passing without lowering its level.
- Update `CHANGELOG.md` under an `Unreleased` heading.
