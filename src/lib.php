<?php
/**
 * This lib file should ONLY be used for global functions to assist with debugging.
 */

function dd($var) {
    var_dump($var);
    die;
}