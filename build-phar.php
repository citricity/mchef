#!/usr/bin/env php
<?php

error_reporting(E_ALL & ~E_DEPRECATED);

/**
 * PHAR Builder for MChef
 * 
 * This script creates a single executable PHAR file from the MChef application.
 */

$pharFile = __DIR__ . '/mchef.phar';
$sourceDir = __DIR__;

// Remove existing phar if it exists
if (file_exists($pharFile)) {
    unlink($pharFile);
}

echo "Building PHAR: {$pharFile}\n";

try {
    $phar = new Phar($pharFile, FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME, 'mchef.phar');
    
    // Start buffering. Mandatory to modify stub.
    $phar->startBuffering();
    
    // Create the default stub
    $phar->setStub($phar->createDefaultStub('phar-entry.php'));
    
    // Add all PHP files from src/
    $phar->buildFromDirectory($sourceDir, '/\.php$/');
   
    // Add vendor directory (excluding dev dependencies as they're already excluded by composer install --no-dev)
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir . '/vendor', RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $relativePath = str_replace($sourceDir . '/', '', $file->getPathname());
            $phar->addFile($file->getPathname(), $relativePath);
        }
    }
    
    // Stop buffering and write changes to disk
    $phar->stopBuffering();
    
    // Make executable
    chmod($pharFile, 0755);
    
    echo "PHAR built successfully: {$pharFile}\n";
    echo "Size: " . number_format(filesize($pharFile)) . " bytes\n";
    
} catch (Exception $e) {
    fwrite(STDERR, "Error building PHAR: " . $e->getMessage() . "\n");
    exit(1);
}