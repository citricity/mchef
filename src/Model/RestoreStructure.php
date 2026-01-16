<?php

namespace App\Model;

class RestoreStructure extends AbstractModel {
    public function __construct(
        /**
         * @var string|null - Path or URL to users CSV file (relative to recipe, absolute, or URL)
         */
        public ?string $users = null,

        /**
         * @var array|string|null - Course categories structure or URL to restore structure JSON
         * Can be:
         * - A string URL pointing to a JSON file with restore structure
         * - An associative array representing category hierarchy with course backups
         */
        public array|string|null $courseCategories = null,
    ) { }
}
