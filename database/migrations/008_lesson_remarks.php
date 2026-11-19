<?php

declare(strict_types=1);

/**
 * A remark per student per lesson, chosen from a fixed list (did not prepare homework,
 * disrupted the lesson, active participation…). A student who was present but got a
 * remark now also has a row (status 'present'); every attendance figure counts only
 * the exception statuses, so such rows do not change any rate.
 */
return [
    'ALTER TABLE attendance_records ADD COLUMN remark VARCHAR(30) NULL AFTER status',
];
