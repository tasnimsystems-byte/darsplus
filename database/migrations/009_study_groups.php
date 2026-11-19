<?php

declare(strict_types=1);

/**
 * Study groups for shared lessons: a named list of students (possibly from several
 * classes) and the teaching assignments it attends. A lesson of a linked assignment
 * lists only the group's students; a group spread over several classes is recorded
 * once, and each student's attendance is kept in the lesson of their own class.
 */

$table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$ts = 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
$big = 'BIGINT UNSIGNED';

return [
    "CREATE TABLE study_groups (
        id $big AUTO_INCREMENT PRIMARY KEY, school_id $big NOT NULL, academic_year_id $big NOT NULL,
        name VARCHAR(80) NOT NULL, subject_id $big NULL, notes VARCHAR(500) NULL,
        created_by $big NULL, updated_by $big NULL, $ts,
        UNIQUE KEY uq_sg_name (academic_year_id, name), UNIQUE KEY uq_sg_school_id (school_id, id),
        CONSTRAINT fk_sg_year FOREIGN KEY (school_id, academic_year_id) REFERENCES academic_years (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_sg_subject FOREIGN KEY (school_id, subject_id) REFERENCES subjects (school_id, id) ON DELETE RESTRICT
    ) $table",

    "CREATE TABLE study_group_students (
        school_id $big NOT NULL, group_id $big NOT NULL, student_id $big NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (group_id, student_id), KEY idx_sgs_student (student_id),
        CONSTRAINT fk_sgs_group FOREIGN KEY (school_id, group_id) REFERENCES study_groups (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_sgs_student FOREIGN KEY (school_id, student_id) REFERENCES students (school_id, id) ON DELETE CASCADE
    ) $table",

    // The group of an assignment; the application checks it belongs to the same school and year.
    'ALTER TABLE teaching_assignments
        ADD COLUMN study_group_id BIGINT UNSIGNED NULL AFTER group_name,
        ADD CONSTRAINT fk_ta_study_group FOREIGN KEY (study_group_id) REFERENCES study_groups (id) ON DELETE SET NULL',
];
