<?php

declare(strict_types=1);

/**
 * Components of the term grade: every assessment type may carry a weight in percent
 * (school settings). When the school has weights, a student's term grade in a subject
 * is the weighted mean of their average in each component; without weights it stays
 * the plain mean of all assessments.
 */
return [
    'ALTER TABLE assessment_types
        ADD COLUMN term_weight DECIMAL(5,2) NULL AFTER is_numeric,
        ADD COLUMN sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER term_weight',
];
