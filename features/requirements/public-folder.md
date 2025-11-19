# Public folder support

## Status - Complete

## Description
Moodle 5.1 has changed the folder structure so that plugins need to be placed in the public folder.

We need to:

* A check to see if the "public" folder exists in the remote moodle repo for the specified tag (Recipe->moodleTag). Will probably modify code in Git.php service for this.
* If the public folder does not exist the behavior will be as is.
* If the public folder does exist then plugins will need to be copied/mounted into the public folder
* Create an integration test to make sure moodle 5 works (no public folder) and moodle 5.1 works with the following plugin config in a recipe:


  "plugins": [
    {
      "repo": "https://github.com/gthomas2/moodle-filter_imageopt",
      "branch": "master"
    }
  ]