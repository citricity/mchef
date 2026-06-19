# Docker Compose Command Detection and Fallback

## Status - Not implemented

## Description

Moodle Chef must support environments where Docker Compose is available as either `docker compose` or `docker-compose`.

The system should detect the working command, persist that choice in main config, and use the configured command for all compose operations.

## Requirements

- Dependency checks must validate Docker Compose by trying command variants in this order:
- configured command from main config (if present)
- `docker compose`
- `docker-compose`
- If a command succeeds in dependency checks, its command string must be written to main config using the Configurator service.
- If neither command succeeds, the system must show a warning that Docker Compose is not installed and fail dependency checks.
- Main config must persist the selected command in a dedicated field (recommended: `dockerComposeCommand`).
- Compose major version validation must remain enforced (`>= 2`) on the command that succeeds.
- All compose execution paths must use configured command selection instead of hardcoded `docker compose`.
- If compose execution fails in runtime paths, the application must return a clear error.
- Existing compose arguments, flags, and env-prefix behavior must remain unchanged.

## User Stories

- As a developer, I want Moodle Chef to automatically use the working compose command so that setup works across different Docker installations.
- As a user, I want failed compose execution to retry with the alternate command so I do not have to manually troubleshoot command naming differences.
- As a maintainer, I want successful fallback to update config automatically so subsequent runs are stable.

## Implementation Notes

- Dependency detection and initial command resolution should be implemented in `Dependencies` service.
- Main config reads/writes must be done through `Configurator` service APIs.
- Runtime compose command construction currently in `Main` and `Docker` services should be switched to config-driven command choice.
- Fallback retry should occur once per failing compose invocation path.
- Auto-rewrite of selected command should happen immediately after successful fallback.

## Acceptance Criteria

- Given an environment with only `docker compose`, dependency checks pass and config is set to `docker compose`.
- Given an environment with only `docker-compose`, dependency checks pass and config is set to `docker-compose`.
- Given configured command is invalid but alternate works at runtime, operation succeeds and config is rewritten to alternate.
- Given both commands fail, dependency checks show a warning that Docker Compose is not installed and runtime operations fail with explicit mention that both command forms were attempted.
- Regression behavior: compose options, arguments, and env-prefix logic are unchanged aside from command token selection.

## Status

- [x] Not started
- [ ] In progress
- [ ] Completed
