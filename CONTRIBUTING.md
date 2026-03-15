# Contributing to Instruckt Drupal

## Local Development Setup

For development purposes, consider using [ddev-drupal-contrib](https://github.com/ddev/ddev-drupal-contrib), which uses a module-as-root layout and helps with dependency management, configuring test suites, and running quality checks and linting.

```bash
# 1. Clone and enter the repo
git clone https://github.com/mvance/instruckt_drupal
cd instruckt_drupal

# 2. Configure DDEV (project name must match module machine name after hyphen→underscore conversion)
ddev config --project-name=instruckt-drupal --project-type=drupal --docroot=web --php-version=8.3

# 3. Install the contrib addon
ddev add-on get ddev/ddev-drupal-contrib

# 4. Start DDEV and scaffold Drupal core
ddev start
ddev poser

# 5. Allow the oomphinc plugin if prompted, then re-run
ddev exec composer config --no-plugins allow-plugins.oomphinc/composer-installers-extender true
ddev poser

# 6. Install Drush (project-level tool, not a module dependency)
ddev exec composer require --dev drush/drush

# 7. Detect installed Drupal version and create the module symlink
ddev config --update && ddev restart

# 8. Configure the private filesystem (required by this module)
mkdir -p web/private
echo "\$settings['file_private_path'] = '/var/www/html/web/private';" >> web/sites/default/settings.php

# 9. Install Drupal and enable the module
ddev drush site:install --yes --account-name=admin --account-pass=admin
ddev drush en instruckt_drupal --yes
```

## Running Tests

Tests require a running DDEV environment and the private filesystem configured.

Run all tests in parallel (default 4 workers):

    ddev test

Specify a worker count explicitly:

    ddev test 4

Run the one-time benchmark (~9–12 min) to find the optimal worker count for your machine:

    ddev benchmark-tests

Pick the process count with the shortest wall time and no timeout failures, then update the default in `.ddev/commands/web/test`.

**OOM / hang mitigation:** each worker enforces a 512 MB PHP memory limit (`.ddev/php/memory.ini`). If tests hang or fail with memory errors, reduce the process count or increase Docker Desktop's memory allocation.

## Mutation Testing

[Infection](https://infection.github.io/) measures test-suite effectiveness by injecting synthetic bugs. Run mutation tests (default 4 threads):

    ddev mutate

Or with an explicit thread count:

    ddev mutate 2

Review `infection.log` (text) or `infection.html` (browser) for escaped mutants. Targets Unit tests only; Kernel/Functional tests are excluded to keep each mutant run sub-second.
