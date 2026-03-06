# PHPQA Continuous Integration

PHPQA is very well suited to running as part of a CI pipeline.

All that is really required is to export the CI environment variable, though you probably also want to ensure that full tests are run.

For example, have a look at the [ci.bash](./../ci.bash) script that PHPQA uses to test itself in CI.

The usage of a generic `ci.bash` script is highly encouraged. Specific CI configuration can then be handled elsewhere, but that CI system should ultimately run the `ci.bash` script to perform the actual checks. This helps you avoid becoming overly coupled to a specific CI platform.

## Running on GitHub Actions

See the [CI workflow](./../.github/workflows/ci.yml) for an example of running PHPQA in GitHub Actions.
