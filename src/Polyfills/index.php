<?php

// Get directory contents non-recursively and iterate.
// Then require each file to load polyfills.
$polyfillDir = __DIR__;
$files = scandir($polyfillDir);
foreach ($files as $file) {
    if ($file === '.' || $file === '..' || $file === basename(__FILE__) || substr(strtolower($file), -4) !== '.php') {
        continue;
    }
    require_once $polyfillDir . DIRECTORY_SEPARATOR . $file;
}
