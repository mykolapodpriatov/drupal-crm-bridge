# Contributing

## Local setup

The module is developed against a DDEV site. From a Drupal 11 project root:

```bash
composer config repositories.crm_bridge path ../drupal-crm-bridge
composer require mykolapodpriatov/crm_bridge:*
ddev drush en crm_bridge
```

## Before opening a pull request

```bash
composer lint      # phpcs, Drupal and DrupalPractice
composer analyse   # phpstan level 8
composer test      # phpunit, all suites
```

CI runs the same three across Drupal 10.3 and 11 on PHP 8.3. A PHPStan ignore
is acceptable only with a comment explaining why the rule cannot be satisfied.

## Conventions

- Nothing that performs network I/O may run in the request path. Entity hooks
  enqueue, queue workers call out.
- Every write to a CRM carries an idempotency key and an origin tag. A change
  that adds a write path without both will not be merged.
- New behaviour arrives with a test. Pure logic goes in `tests/src/Unit`,
  anything touching the container or storage in `tests/src/Kernel`, and routes
  and forms in `tests/src/Functional`.
