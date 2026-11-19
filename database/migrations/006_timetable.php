<?php

declare(strict_types=1);

/**
 * Weekly timetable, imported from the DarsPlus timetable generator (timetable-web).
 * One timetable per school year: a re-import replaces the periods and slots of that
 * year in one transaction; teaching assignments are matched and updated, never deleted.
 */

$table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$ts = 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
$big = 'BIGINT UNSIGNED';

return [
    "CREATE TABLE timetable_periods (
        id $big AUTO_INCREMENT PRIMARY KEY, school_id $big NOT NULL, academic_year_id $big NOT NULL,
        period_number TINYINT UNSIGNED NOT NULL, name VARCHAR(60) NOT NULL DEFAULT '',
        start_time TIME NULL, end_time TIME NULL, is_break TINYINT(1) NOT NULL DEFAULT 0, $ts,
        UNIQUE KEY uq_tp (academic_year_id, period_number),
        CONSTRAINT fk_tp_year FOREIGN KEY (school_id, academic_year_id) REFERENCES academic_years (school_id, id) ON DELETE RESTRICT
    ) $table",

    // weekday as PHP date('w'): 0 = Sunday … 6 = Saturday.
    "CREATE TABLE timetable_slots (
        id $big AUTO_INCREMENT PRIMARY KEY, school_id $big NOT NULL, academic_year_id $big NOT NULL,
        teaching_assignment_id $big NOT NULL, weekday TINYINT UNSIGNED NOT NULL, period_number TINYINT UNSIGNED NOT NULL,
        room_id $big NULL, $ts,
        UNIQUE KEY uq_ts (teaching_assignment_id, weekday, period_number),
        KEY idx_ts_day (school_id, academic_year_id, weekday, period_number),
        CONSTRAINT fk_ts_year FOREIGN KEY (school_id, academic_year_id) REFERENCES academic_years (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_ts_assignment FOREIGN KEY (school_id, teaching_assignment_id) REFERENCES teaching_assignments (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_ts_room FOREIGN KEY (school_id, room_id) REFERENCES rooms (school_id, id) ON DELETE RESTRICT
    ) $table",

    // History of imports; the newest row of a year also gives its school days.
    "CREATE TABLE timetable_imports (
        id $big AUTO_INCREMENT PRIMARY KEY, school_id $big NOT NULL, academic_year_id $big NOT NULL,
        source_name VARCHAR(190) NOT NULL DEFAULT '', days VARCHAR(20) NOT NULL DEFAULT '0,1,2,3,4',
        summary LONGTEXT NULL, imported_by $big NULL, $ts,
        KEY idx_ti_year (school_id, academic_year_id, id),
        CONSTRAINT fk_ti_year FOREIGN KEY (school_id, academic_year_id) REFERENCES academic_years (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_ti_user FOREIGN KEY (imported_by) REFERENCES users (id) ON DELETE SET NULL
    ) $table",
];
