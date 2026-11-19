<?php

declare(strict_types=1);

/**
 * Report cards: the homeroom teacher's remark for each student and term, printed in the
 * remarks box of the certificate. Everything else on a certificate (grades, verbal grades,
 * absences and late arrivals) is computed when it is printed.
 */
$table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    "CREATE TABLE report_card_remarks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        school_id BIGINT UNSIGNED NOT NULL,
        student_id BIGINT UNSIGNED NOT NULL,
        term_id BIGINT UNSIGNED NOT NULL,
        remark TEXT NOT NULL,
        updated_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_rcr (student_id, term_id),
        CONSTRAINT fk_rcr_student FOREIGN KEY (school_id, student_id) REFERENCES students (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_rcr_term FOREIGN KEY (school_id, term_id) REFERENCES academic_terms (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_rcr_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
    ) $table",
];
