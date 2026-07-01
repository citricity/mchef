<?php
/**
 * Migrate a live Moodle MySQL database to a SQLite snapshot file.
 *
 * Usage: php migrate-to-sqlite.php <moodle-root> <output-path>
 *
 * Must be run inside the mchef Docker container where:
 *   - Moodle is installed at <moodle-root> with a working config.php
 *   - The patched SQLite files have been copied to /tmp/playground-export/
 *
 * Produces a SQLite .sq3 file suitable for post-processing by
 * generate-install-snapshot.sh (PREBUILT_DB_PATH mode).
 */

define('CLI_SCRIPT', true);
define('CACHE_DISABLE_ALL', true);
define('CACHE_DISABLE_STORES', true);

$moodleRoot = $argv[1] ?? '/var/www/html';
$outPath    = $argv[2] ?? '/tmp/playground-export/export.sq3';
$patchDir   = dirname(__FILE__);

if (!is_file($moodleRoot . '/config.php')) {
    fwrite(STDERR, "Error: config.php not found at $moodleRoot\n");
    exit(1);
}

require($moodleRoot . '/config.php');

// Load patched SQLite driver classes (not present in standard Moodle)
require($patchDir . '/sqlite3_pdo_moodle_database.php');
require($patchDir . '/sqlite_sql_generator.php');
require($patchDir . '/encryption.php');

if (file_exists($outPath)) {
    unlink($outPath);
}

fwrite(STDERR, "Connecting to SQLite: $outPath\n");

$sqliteDB = new sqlite3_pdo_moodle_database();
$sqliteDB->connect('localhost', '', '', 'moodle_export', $CFG->prefix, [
    'dbpersist'       => 0,
    'dbport'          => '',
    'dbsocket'        => '',
    'dbhandlesoptions' => false,
    'file'            => $outPath,
]);

// Apply performance pragmas for bulk load
$sqliteDB->execute("PRAGMA journal_mode=MEMORY");
$sqliteDB->execute("PRAGMA synchronous=OFF");
$sqliteDB->execute("PRAGMA temp_store=MEMORY");
$sqliteDB->execute("PRAGMA cache_size=-16000");

$sqliteManager = $sqliteDB->get_manager();
$mysqlManager  = $DB->get_manager();

fwrite(STDERR, "Building SQLite schema from XMLDB definitions...\n");

// Use Moodle's XMLDB loader to iterate all installed component schemas
$tables = [];
$components = core_component::get_component_list();
foreach ($components as $type => $comps) {
    foreach ($comps as $component => $componentdir) {
        $xmldbFile = $componentdir . '/db/install.xml';
        if (!is_file($xmldbFile)) {
            continue;
        }
        $xmldb = new xmldb_file($xmldbFile);
        $xmldb->loadXMLStructure();
        $structure = $xmldb->getStructure();
        if (!$structure) {
            continue;
        }
        foreach ($structure->getTables() as $table) {
            $tableName = $table->getName();
            if (isset($tables[$tableName])) {
                continue;
            }
            $tables[$tableName] = true;
            if (!$DB->get_manager()->table_exists($table)) {
                continue; // Table not installed in MySQL
            }
            try {
                $sqliteManager->create_table($table);
            } catch (Throwable $e) {
                fwrite(STDERR, "Warning: Could not create table $tableName: " . $e->getMessage() . "\n");
            }
        }
    }
}

// Also create any tables that exist in MySQL but were not covered by XMLDB
// (e.g. from plugins installed manually or external tables)
$mysqlTables = $DB->get_tables(false);
foreach ($mysqlTables as $tableName) {
    if (isset($tables[$tableName])) {
        continue;
    }
    fwrite(STDERR, "Warning: table $tableName has no XMLDB definition — schema derived from live columns\n");
    $columns = $DB->get_columns($tableName, false);
    if (empty($columns)) {
        continue;
    }
    $xmldbTable = new xmldb_table($tableName);
    foreach ($columns as $col) {
        $xmldbField = new xmldb_field($col->name);
        $xmldbField->setType(map_column_meta_to_xmldb_type($col->meta_type));
        $xmldbField->setLength($col->max_length > 0 ? $col->max_length : null);
        $xmldbField->setNotNull($col->not_null);
        $xmldbField->setDefault($col->has_default ? $col->default_value : null);
        $xmldbTable->addField($xmldbField);
    }
    try {
        $sqliteManager->create_table($xmldbTable);
        $tables[$tableName] = true;
    } catch (Throwable $e) {
        fwrite(STDERR, "Warning: Could not create fallback table $tableName: " . $e->getMessage() . "\n");
    }
}

fwrite(STDERR, "Copying data table by table...\n");

$BATCH_SIZE = 500;
foreach (array_keys($tables) as $tableName) {
    $count = $DB->count_records($tableName);
    if ($count === 0) {
        continue;
    }

    fwrite(STDERR, "  $tableName ($count rows)\n");

    $rs    = $DB->get_recordset($tableName);
    $batch = [];
    foreach ($rs as $record) {
        $batch[] = (array)$record;
        if (count($batch) >= $BATCH_SIZE) {
            $sqliteDB->insert_records($tableName, $batch);
            $batch = [];
        }
    }
    $rs->close();
    if (!empty($batch)) {
        $sqliteDB->insert_records($tableName, $batch);
    }
}

$sqliteDB->dispose();

$size = filesize($outPath);
fwrite(STDERR, "Migration complete. SQLite file: $outPath ($size bytes)\n");

/**
 * Map a Moodle database_column_info meta_type to the corresponding XMLDB type constant.
 */
function map_column_meta_to_xmldb_type(string $metaType): int {
    return match ($metaType) {
        'I', 'R' => XMLDB_TYPE_INTEGER,
        'F'      => XMLDB_TYPE_FLOAT,
        'N'      => XMLDB_TYPE_NUMBER,
        'B'      => XMLDB_TYPE_BINARY,
        'X'      => XMLDB_TYPE_TEXT,
        default  => XMLDB_TYPE_CHAR,
    };
}
