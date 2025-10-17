# Publish Feature

## Status - Completed

## Description

A new "CI" command is required which will initially have 1 argument (recipe location) and 1 option "--publish"

E.g. mchef ci recipe.json --publish=v1.5.0

A new field will be added to the Recipe model - "publishTagPrefix"

The publish argument's value is the tag that will be applied to the build. Note, it will be concatenated to the end of the recipes publishTagPrefix (sanitized), or if not present, sanitized recipe name - e.g:

my-moodle-app:v1.5.0

It will do a build in the same way that the main service currently does EXCEPT it will override the following recipe fields

cloneRepoPlugins: false
mountPlugins: false
developer: false
includePhpUnit: false
includeBehat: false
includeXdebug: false

Once completed, it will tag this build with the argument value and publish it to a docker hub or github packages.
It will use environment variables for the type of repo, credentials, etc. The idea is that you will set these variables in a github repository and then you can use the ci command as part of a github workflow.
If those environment variables are missing it will simply build the image and spit out a warning that the publishing state requires those environment variables.

Env vars:

MCHEF_REGISTRY_URL
MCHEF_REGISTRY_USERNAME
MCHEF_REGISTRY_PASSWORD
MCHEF_REGISTRY_TOKEN (if token based like github)