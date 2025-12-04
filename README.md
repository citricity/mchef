# MChef

MChef is a command-line tool designed to manage and automate various tasks related to deploying Moodle instances with plugins in Docker containers. It leverages the `splitbrain/php-cli` library to provide a robust CLI interface.

## Features

- Recipe management
- Plugin integration
- Docker support

## Requirements

- PHP 8.x or higher
- Composer (https://getcomposer.org/download/)

## Installation

1. Clone the repository:

    ```sh
    git clone https://github.com/gthomas2/mchef.git
    cd mchef
    ```

2. Install dependencies using Composer:

    ```sh
    composer install
    ```

    or alternatively, if you installed composer in the project directory
    ```sh
    php composer.phar install
    ```

3. Install the application itself:

    ```sh
    php mchef.php -i
    ```

    This will also create a symlink so you can use the command
    ```sh
    mchef.php [command] [options]
    ```
    afterwards.


## Usage

You should create a folder for hosting your project.
In this folder you will need a recipe file - see the example-mrecipe.json file
To use MChef, run the following command in your project folder:

```sh
mchef.php [command] [options]
```

For example - if you have a recipe called recipe.json in your project folder you would run:
```sh
mchef.php recipe.json
```

To see an overview of commands, run:

```sh
mchef.php
```

To run the example recipe use:

```sh
mchef.php example-mrecipe.json
```

Search in  "/src/Model/Recipe.php" for all the possible ingredients of your recipe.
Enjoy cooking.

## Recipe File Structure

The recipe file is a JSON configuration file that defines how your Moodle instance should be set up. Below is a comprehensive reference of all available attributes.

### Basic Attributes

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `name` | string | `null` | Unique identifier for your recipe. This is used to identify the recipe instance. |
| `moodleTag` | string | **Required** | Moodle version tag (e.g., `"v4.1.0"`). This determines which Moodle version will be installed. |
| `plugins` | array | `null` | Array of plugin definitions. See [Plugins](#plugins) section below. |
| `containerPrefix` | string | `"mc"` | Prefix for Docker container names. Setting this to `"example"` results in containers like `example-db`, `example-moodle`, `example-behat`. |
| `host` | string | `null` | Web hostname (leave blank for default of `localhost`). |
| `port` | int | `null` | Web port (leave blank for default of `80`). |
| `updateHostHosts` | bool | `null` | If `true`, automatically adds the `host` value to `/etc/hosts` if not present. |
| `dbType` | string | `"pgsql"` | Database type. Valid values: `"pgsql"` (PostgreSQL) or `"mysql"` / `"mysqli"` (MySQL). |
| `dbHostPort` | string | `null` | Database host port to forward to (e.g., `"55435"`). This allows you to connect to the database from your host machine. |
| `mountPlugins` | bool | `null` | If `true`, uses volume mounts for plugins, facilitating local development. When `false`, plugins are shallow-cloned directly in the Docker image. |
| `config` | object | `{}` | Moodle configuration object. See [Config](#config) section below. |
| `configFile` | string | `null` | Path to a custom Moodle config.php file to use instead of the generated one. |
| `sampleData` | object | `null` | Sample data configuration for test data generation. See [Sample Data](#sample-data) section below. |

### Plugins

The `plugins` attribute accepts an array of plugin definitions. Each plugin can be defined in two ways:

**Simple string format:**
```json
"plugins": [
  "https://github.com/user/plugin-repo.git"
]
```

**Object format (with branch specification):**
```json
"plugins": [
  {
    "repo": "https://github.com/user/plugin-repo.git",
    "branch": "master",
    "upstream": "https://github.com/upstream/plugin-repo.git"
  }
]
```

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `repo` | string | **Required** | Repository URL (mandatory). Can be HTTPS or SSH format. |
| `branch` | string | `"main"` | Branch name to checkout. |
| `upstream` | string | `null` | Upstream repository URL (optional). Useful for maintaining forks. |

### Config

The `config` object allows you to customize Moodle's configuration settings:

```json
"config": {
  "prefix": "mdl_",
  "directorypermissions": "02777",
  "admin": "admin",
  "lang": "en",
  "timezone": "UTC",
  "defaultblocks": ""
}
```

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `prefix` | string | `"mdl_"` | Database table prefix. |
| `directorypermissions` | string | `"02777"` | Directory permissions for Moodle data directories. |
| `admin` | string | `"admin"` | Default admin username. |
| `lang` | string | `null` | Default language code (e.g., `"en"`, `"es"`, `"fr"`). If not set, uses global config or defaults to `"en"`. |
| `timezone` | string | `null` | Default timezone (e.g., `"UTC"`, `"America/New_York"`). |
| `defaultblocks` | string | `null` | Default blocks configuration. |

### Sample Data

The `sampleData` object configures automatic test data generation using Moodle's `tool_generator`. This is useful for creating realistic test environments with courses, users, activities, and content.

**New format (recommended):**
```json
"sampleData": {
  "mode": "site",
  "size": "S",
  "fixeddataset": false,
  "filesizelimit": false,
  "additionalmodules": []
}
```

**Legacy format (still supported):**
```json
"sampleData": {
  "students": 200,
  "teachers": 50,
  "categories": 10,
  "courses": 30,
  "courseSize": "small"
}
```

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `mode` | string | `"site"` | Generation mode: `"site"` (uses `maketestsite.php` for full site generation) or `"course"` (uses `maketestcourse.php` for individual courses). |
| `size` | string | `"M"` | Size of generated data. Valid values: `"XS"`, `"S"`, `"M"`, `"L"`, `"XL"`, `"XXL"`. Larger sizes create more content (courses, users, activities, files). |
| `fixeddataset` | bool | `false` | If `true`, uses a fixed dataset instead of randomly generated data. Useful for reproducible tests. |
| `filesizelimit` | int\|bool | `false` | Maximum file size in bytes for generated files. Set to `false` for no limit. |
| `additionalmodules` | array | `[]` | Additional modules to include when creating courses (e.g., `["quiz", "forum"]`). Modules must implement the `course_backend_generator_create_activity` function. |
| `students` | int | `null` | (Legacy) Number of students to create. |
| `teachers` | int | `null` | (Legacy) Number of teachers to create. |
| `categories` | int | `null` | (Legacy) Number of course categories to create. |
| `courses` | int | `null` | (Legacy) Number of courses to create. |
| `courseSize` | string | `null` | (Legacy/Deprecated) Course size: `"small"`, `"medium"`, `"large"`, or `"random"`. Use `size` instead. |

**Size Reference:**
- **XS**: ~10KB; creates in ~1 second
- **S**: ~10MB; creates in ~30 seconds
- **M**: ~100MB; creates in ~2 minutes
- **L**: ~1GB; creates in ~30 minutes
- **XL**: ~10GB; creates in ~2 hours
- **XXL**: ~20GB; creates in ~4 hours

**Note:** The new format uses Moodle's built-in `tool_generator` which creates realistic test data with activities, files, forums, and automatic user enrollment. The legacy format creates basic courses without rich content.

## Behat

### Running a behat test without viewing progress (headless)

```sh
mchef.php behat
```

### To run and view a behat test in progress

You would do this if you needed to debug what it was doing - e.g when you have an "And I pause" step.
Start the behat test as follows:

```sh
mchef.php behat --profile=chrome
```

To view the test running, open the following URL
http://localhost:7900/?autoconnect=1&resize=scale&password=secret
