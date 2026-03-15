# Contributing to Instruckt Drupal

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
