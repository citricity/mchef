#!/usr/bin/env php
<?php

/**
 * MChef PHAR Entry Point
 * 
 * This file serves as the entry point when MChef is run as a PHAR.
 * It mimics the behavior of mchef.php but works within the PHAR context.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

// When running as PHAR, __DIR__ points to the PHAR's virtual filesystem
$vendor_path = 'phar://mchef.phar/vendor/autoload.php';

// Fallback to regular autoloader if not in PHAR
if (!file_exists($vendor_path)) {
    $vendor_path = __DIR__ . '/vendor/autoload.php';
}

if (!file_exists($vendor_path)) {
    fwrite(STDERR, "Autoloader not found. This PHAR may be corrupted.\n");
    exit(1);
}

require $vendor_path;

use App\MChefCLI;

$cli = new MChefCLI();
$cli->run();