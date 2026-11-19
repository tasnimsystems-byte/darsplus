<?php

declare(strict_types=1);

/**
 * A final term grade typed directly in the grade sheet: one per student, subject and term.
 * It replaces the grade computed from the assessments in every figure (grade sheet, student page,
 * reports, certificates). Deleting it brings the computed grade back.
 */
$table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    "CREATE TABLE grade_overrides (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        school_id BIGINT UNSIGNED NOT NULL,
        student_id BIGINT UNSIGNED NOT NULL,
        subject_id BIGINT UNSIGNED NOT NULL,
        term_id BIGINT UNSIGNED NOT NULL,
        grade TINYINT UNSIGNED NOT NULL,
        updated_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_go (student_id, subject_id, term_id),
        KEY idx_go_term (term_id),
        CONSTRAINT fk_go_student FOREIGN KEY (school_id, student_id) REFERENCES students (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_go_subject FOREIGN KEY (school_id, subject_id) REFERENCES subjects (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_go_term FOREIGN KEY (school_id, term_id) REFERENCES academic_terms (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_go_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
    ) $table",
];
