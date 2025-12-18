<?php

namespace App\Model;

class SampleData extends AbstractModel {
    public function __construct(
        /**
         * @var string|null - Generation mode: "site" (uses maketestsite.php) or "course" (uses maketestcourse.php)
         * Defaults to "site"
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
         * Modules must implement the course_backend_generator_create_activity function
         */
        public ?array $additionalmodules = null,

        /**
         * @var int|null - Number of courses to create (only used when mode is "course")
         * Defaults to 10
         */
        public ?int $courses = null,
    ) { }
}
