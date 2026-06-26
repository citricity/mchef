# Publish Feature

## Status - Completed

## Description

A new "CI" command is required which supports one or more recipe file arguments (base + overrides), and either a publish tag or a local-only tag option.

Examples:

- mchef ci recipe.json --publish=v1.5.0
- mchef ci recipe.json server.json --publish=v1.5.0
- mchef ci recipe.json server.json --tag=latest

A new field will be added to the Recipe model - "publishTagPrefix"

The publish/tag argument's value is the tag that will be applied to the build. Note, it will be concatenated to the end of the recipe's publishTagPrefix (sanitized), or if not present, sanitized recipe name - e.g:

my-moodle-app:v1.5.0

It will do a build in the same way that the main service currently does EXCEPT it will override the following recipe fields.

When multiple recipe files are provided, they are merged left-to-right and later files override earlier values.

cloneRepoPlugins: false
mountPlugins: false
developer: false
includePhpUnit: false
includeBehat: false
includeXdebug: false

Once completed, it will tag this build with the argument value.

- `--publish=<tag>`: attempt registry publish after build.
- `--tag=<tag>`: build and tag locally only; never publish.

If both `--publish` and `--tag` are provided, the command should fail validation.

Publish should target Docker Hub or GitHub packages when configured.
It will use environment variables for the type of repo, credentials, etc. The idea is that you will set these variables in a github repository and then you can use the ci command as part of a github workflow.
If those environment variables are missing it will simply build the image and spit out a warning that the publishing state requires those environment variables.

Env vars:

MCHEF_REGISTRY_URL
MCHEF_REGISTRY_USERNAME
MCHEF_REGISTRY_PASSWORD
MCHEF_REGISTRY_TOKEN (if token based like github)