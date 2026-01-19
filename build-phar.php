#!/usr/bin/env php
<?php

error_reporting(E_ALL & ~E_DEPRECATED);

/**
 * PHAR Builder for MChef
 * 
 * This script creates a single executable PHAR file from the MChef application.
 */

// Enable PHAR creation (in case it's disabled)
ini_set('phar.readonly', 'Off');

$pharFile = 'mchef.phar';
$sourceDir = __DIR__;

// Remove existing PHAR if it exists
if (file_exists($pharFile)) {
    unlink($pharFile);
}

echo "Building PHAR: " . realpath('.') . '/' . $pharFile . "\n";

try {
    $phar = new Phar($pharFile);
    $phar->startBuffering();
    
    // Set the entry point
    $phar->setStub($phar->createDefaultStub('phar-entry.php'));
    
    // Add the entry point file
    $phar->addFile('phar-entry.php');
    
    // Add all source files
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator('src', RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $relativePath = 'src/' . substr($file->getRealPath(), strlen(realpath('src')) + 1);
            $phar->addFile($file->getRealPath(), $relativePath);
        }
    }
    
    // Add vendor directory (production dependencies)
    if (is_dir('vendor')) {
        $vendorIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator('vendor', RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($vendorIterator as $file) {
            if ($file->isFile()) {
                $relativePath = 'vendor/' . substr($file->getRealPath(), strlen(realpath('vendor')) + 1);
                $phar->addFile($file->getRealPath(), $relativePath);
            }
        }
    }
    
    // Add any other necessary directories
    $additionalDirs = ['config', 'assets', 'data', 'templates', 'scripts'];
    foreach ($additionalDirs as $dir) {
        if (is_dir($dir)) {
            $dirIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($dirIterator as $file) {
                if ($file->isFile()) {
                    $relativePath = $dir . '/' . substr($file->getRealPath(), strlen(realpath($dir)) + 1);
                    $phar->addFile($file->getRealPath(), $relativePath);
                }
            }
        }
    }
    
    $phar->stopBuffering();
    
    // Make it executable
    chmod($pharFile, 0755);
    
    echo "PHAR built successfully: $pharFile\n";
    echo "File size: " . formatBytes(filesize($pharFile)) . "\n";
    
} catch (Exception $e) {
    fwrite(STDERR, "Error building PHAR: " . $e->getMessage() . "\n");
    exit(1);
}

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB');
    
    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}