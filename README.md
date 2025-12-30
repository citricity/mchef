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

## Default Admin Credentials

When MChef installs a Moodle site, it creates an admin user with the following default credentials:

- **Username:** `admin`
- **Password:** `123456`

These defaults can be customized in two ways:

1. **Per Recipe:** Add an `adminPassword` field to your recipe JSON file (see [Recipe File Structure](#recipe-file-structure) below).
2. **Global Config:** Set a default admin password for all recipes using the global config: `mchef.php config --password`

If neither is specified, the default password `123456` will be used.

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
| `adminPassword` | string | `null` | Admin password for the Moodle installation. If not specified, uses global config password or defaults to `"123456"`. See [Default Admin Credentials](#default-admin-credentials) above. |
| `config` | object | `{}` | Moodle configuration object. See [Config](#config) section below. |
| `configFile` | string | `null` | Path to a custom Moodle config.php file to use instead of the generated one. |
| `sampleData` | object | `null` | Sample data configuration for test data generation. See [Sample Data](#sample-data) section below. |
| `restoreStructure` | object\|string | `null` | Restore structure configuration for loading users and courses. Can be an object or a URL string pointing to a JSON file. See [Restore Structure](#restore-structure) section below. |

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

The `sampleData` object configures automatic test data generation using Moodle's built-in `tool_generator`. This tool creates realistic test environments with courses, users, activities, files, forums, and automatic user enrollment. MChef uses Moodle's `tool_generator` to generate this test data automatically during Moodle installation.

**Configuration example:**
```json
"sampleData": {
  "mode": "site",
  "size": "S",
  "fixeddataset": false,
  "filesizelimit": false,
  "additionalmodules": []
}
```

**Site mode example:**
```json
"sampleData": {
  "mode": "site",
  "size": "M",
  "fixeddataset": true
}
```

**Course mode example:**
```json
"sampleData": {
  "mode": "course",
  "size": "L",
  "courses": 20,
  "additionalmodules": ["quiz", "forum"]
}
```

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `mode` | string | `"site"` | Generation mode: `"site"` (uses `maketestsite.php` for full site generation) or `"course"` (uses `maketestcourse.php` for individual courses). |
| `size` | string | `"M"` | Size of generated data. Valid values: `"XS"`, `"S"`, `"M"`, `"L"`, `"XL"`, `"XXL"`. Larger sizes create more content (courses, users, activities, files). |
| `fixeddataset` | bool | `false` | If `true`, uses a fixed dataset instead of randomly generated data. Useful for reproducible tests. |
| `filesizelimit` | int\|bool | `false` | Maximum file size in bytes for generated files. Set to `false` for no limit. |
| `additionalmodules` | array | `[]` | Additional modules to include when creating courses (e.g., `["quiz", "forum"]`). Modules must implement the `course_backend_generator_create_activity` function. |
| `courses` | int | `10` | Number of courses to create when `mode` is `"course"`. Not used in `"site"` mode. |

**Size Reference:**
- **XS**: ~10KB; creates in ~1 second
- **S**: ~10MB; creates in ~30 seconds
- **M**: ~100MB; creates in ~2 minutes
- **L**: ~1GB; creates in ~30 minutes
- **XL**: ~10GB; creates in ~2 hours
- **XXL**: ~20GB; creates in ~4 hours

**Related Documentation:**
- [Moodle Developer Resources - Generator tool](https://moodledev.io/general/development/tools/generator) - Official Moodle documentation on the tool_generator
- [Moodle PHP Documentation - tool_generator](https://phpdoc.moodledev.io/main/df/db7/group__tool__generator.html) - PHP API documentation for tool_generator

**How it works:**
- **Site mode**: When `mode` is set to `"site"`, MChef executes Moodle's `admin/tool/generator/cli/maketestsite.php` script, which creates a complete test site with courses, users, activities, and content based on the specified size.
- **Course mode**: When `mode` is set to `"course"`, MChef executes Moodle's `admin/tool/generator/cli/maketestcourse.php` script for each course specified by the `courses` property. This allows you to create individual test courses with specific configurations.

### Restore Structure

The `restoreStructure` object allows you to load users and courses from external sources (CSV files and MBZ backup files) into your Moodle instance. This is useful for setting up development or testing environments with specific data.

**Configuration example:**
```json
{
  "name": "example",
  "moodleTag": "v4.1.0",
  "phpVersion": "8.0",
  "restoreStructure": {
    "users": "users.csv",
    "courseCategories": {
      "Art": [
        "backupart1.mbz",
        "https://someurl.com/backupart2.mbz"
      ],
      "Science": {
        "Biology": [
          "https://someurl.com/backupbio1.mbz",
          "https://someurl.com/backupbio2.mbz"
        ],
        "Chemistry": [
          "chem1.mbz",
          "https://someurl.com/backupchem1.mbz"
        ]
      }
    }
  }
}
```

**Loading restore structure from URL:**
```json
{
  "name": "example",
  "moodleTag": "v4.1.0",
  "phpVersion": "8.0",
  "restoreStructure": "https://my.cdn/restoreStructure.json"
}
```

When `restoreStructure` is a string URL, MChef will download the JSON file from that URL and use it as the restore structure configuration. The downloaded JSON must follow the same structure as the `restoreStructure` object.

| Property | Type | Description |
|----------|------|-------------|
| `users` | string | Path or URL to a CSV file containing user data. Can be: a relative path (relative to the recipe file), an absolute path, or a URL. The CSV file will be processed using Moodle's Upload users tool. |
| `courseCategories` | object | Recursive object representing a category hierarchy. Each key is a category name, and the value can be either: an array of MBZ backup file paths (strings) to restore as courses in that category, or another object representing nested subcategories. |

**Category Structure:**
- **Category with courses**: A category name followed by an array of MBZ file paths:
  ```json
  "Art": ["backup1.mbz", "backup2.mbz"]
  ```
- **Nested categories**: A category name followed by another category structure object:
  ```json
  "Science": {
    "Biology": ["bio1.mbz"],
    "Chemistry": ["chem1.mbz", "chem2.mbz"]
  }
  ```

**File Paths:**
All file paths (for `users` CSV and MBZ backup files) can be specified as:
- **Relative paths**: Relative to the recipe file location (e.g., `"users.csv"`, `"backups/course1.mbz"`)
- **Absolute paths**: Full system paths (e.g., `"/path/to/users.csv"`)
- **URLs**: HTTP/HTTPS URLs that will be downloaded automatically (e.g., `"https://example.com/users.csv"`)

**How it works:**
1. **Users**: MChef uses Moodle's Upload users CLI tool (`admin/tool/uploaduser/cli/uploaduser.php` or `public/admin/tool/uploaduser/cli/uploaduser.php` for Moodle 5.1+) to import users from the CSV file. The CSV file is automatically copied or downloaded into the Moodle container before processing.
2. **Categories**: MChef creates the category hierarchy using a CLI script (`admin/cli/create_category_mchef.php`). Categories are created recursively based on the structure defined in `restoreStructure.courseCategories`. **Only categories specified under `restoreStructure.courseCategories` will be created** - no other categories are created automatically.
3. **Courses**: MChef restores course backups using Moodle's `admin/cli/restore_backup.php` script. Each MBZ file is automatically copied or downloaded into the Moodle container, then restored to the appropriate category.

**Notes:**
- The restore structure is processed after Moodle installation and sample data generation (if configured).
- **Categories are only created if they are specified under `restoreStructure.courseCategories`** - the recipe file will not create any categories that are not explicitly defined in the restore structure.
- Course backups are restored in the order they appear in the configuration.
- The `users` CSV file must follow Moodle's upload users format. See [Moodle Upload users documentation](https://docs.moodle.org/501/en/Upload_users) for details.

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
