<?php

namespace App\Model;

class SampleData extends AbstractModel {
    public function __construct(
        /**
         * @var string|null - Generation mode: "site" (uses maketestsite.php) or "course" (uses maketestcourse.php)
         * Defaults to "site" for new configurations, "course" for legacy
         */
        public ?string $mode = null,

        /**
         * @var string|null - Size of generated data: XS, S, M, L, XL, XXL (matches tool_generator)
         * Use constants from \App\Constants\SampleDataSize (e.g., SampleDataSize::M)
         * Defaults to "M"
         */
        public ?string $size = null,

        /**
         * @var bool|null - Use fixed dataset instead of randomly generated data
         */
        public ?bool $fixeddataset = null,

        /**
         * @var int|null - Maximum file size in bytes for generated files
         */
        public ?int $filesizelimit = null,

        /**
         * @var array|null - Additional modules to include (e.g., ["quiz", "forum"])
         */
        public ?array $additionalmodules = null,

        // Legacy/Override properties - kept for backward compatibility and fine-grained control
        /**
         * @var int|null - Number of students to create (legacy/override)
         */
        public ?int $students = null,

        /**
         * @var int|null - Number of teachers to create (legacy/override)
         */
        public ?int $teachers = null,

        /**
         * @var int|null - Number of categories to create (legacy/override)
         */
        public ?int $categories = null,

        /**
         * @var int|null - Number of courses to create (legacy/override)
         */
        public ?int $courses = null,

        /**
         * @var string|null - Course size: "small", "medium", "large", "random" (deprecated, use size instead)
         */
        public ?string $courseSize = null,
    ) { }
}
