# PR Title

Add a tenant setup CLI command

# PR Description

## Summary

This PR adds a new tenant setup flow, which does exaclty the same as the original new tenant backend module but is accessible through a new CLI command.

It introduces a dedicated CLI command for applying tenant defaults to an existing configuration folder and refactors the backend new-tenant module so that both use the same shared setup logic and future changes affect both.

This PR builds on top of:
1. the Basic Viewer foundation
2. the root page tree setup

### TODO Add link to previous PRs

## Changes

- add the CLI command
- make the CLI and backend module share one implementation for tenant defaults
  - add `TenantModuleSetupService`
  - add `TenantDefaultsSetupService`
- support full tenant setup or selected setup steps:
  - `--namespaces`
  - `--formats` as alias for `--namespaces`
  - `--structures`
  - `--metadata`
  - `--solr-core`

## New Tenant module refactor

This PR moves significant parts out of the original `NewTenantController`.

Because the tenant setup logic is now shared between the backend module and the CLI command, it makes sense to extract that logic into shared services. The `NewTenantController` now acts as a coordinator that delegates to those services.

- `NewTenantController` acts as a coordinator
- setup actions delegate to `TenantDefaultsSetupService`
- the same service is used by the CLI command

This keeps the backend and CLI behavior aligned and avoids maintaining two separate implementations of the same setup steps.
