<?php

namespace App\Enums;

enum DatabaseType: string {
    case Postgres = 'pgsql';
    case Mysql = 'mysql';
    case Mariadb = 'mariadb';
}
