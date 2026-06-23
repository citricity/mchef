<?php

namespace App\Enums;

enum DebugMode: string {
    case ERROR = 'error';
    case WARNING = 'warning';
    case VERBOSE = 'verbose';
    case NONE = 'none';
}