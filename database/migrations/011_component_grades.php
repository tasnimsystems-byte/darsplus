<?php

declare(strict_types=1);

/**
 * A component grade typed directly in the grade sheet is kept as a "summary" assessment:
 * one per lesson, term and component (assessment type), out of 100, published. When a student
 * has a summary score in a component, it replaces their average of that component's other
 * assessments in every grade figure.
 */
return [
    'ALTER TABLE assessments ADD COLUMN is_summary TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
];
