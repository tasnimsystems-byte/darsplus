<?php

declare(strict_types=1);

/**
 * Subjects without grades: the homeroom lesson ("التربية", "שעת חינוך") and the morning homeroom
 * meeting. Their grade sheet records, per student and term, the number of absences and late
 * arrivals and the homeroom teacher's remark instead of grades; they are left out of averages and
 * certificates. The counts are stored only when the teacher sets them – otherwise the certificate
 * counts the lesson attendance.
 */
return [
    'ALTER TABLE subjects ADD COLUMN is_graded TINYINT(1) NOT NULL DEFAULT 1 AFTER code',
    "UPDATE subjects SET is_graded = 0
      WHERE TRIM(name) IN ('التربية', 'تربية', 'חינוך', 'שעת חינוך', 'שיעור חינוך') OR name LIKE 'لقاء المربي%' OR name LIKE 'מפגש מחנך%'",
    'ALTER TABLE report_card_remarks
        MODIFY remark TEXT NULL,
        ADD COLUMN absences SMALLINT UNSIGNED NULL AFTER remark,
        ADD COLUMN lates SMALLINT UNSIGNED NULL AFTER absences',
];
