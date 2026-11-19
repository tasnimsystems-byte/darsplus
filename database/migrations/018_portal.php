<?php

declare(strict_types=1);

/**
 * Student and parent portal.
 *  - students.user_id: the account of a student (one account per student record).
 *  - guardians.user_id: the account of a parent; one account may be linked to several guardian rows
 *    (the same parent of several children).
 *  - certificate_publications: the principal publishes the certificates of a class for a term;
 *    only then do its students and parents see them.
 */
$table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    'ALTER TABLE students ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER status,
        ADD UNIQUE KEY uq_students_user (user_id),
        ADD CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL',
    'ALTER TABLE guardians ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER email,
        ADD KEY idx_gd_user (user_id),
        ADD CONSTRAINT fk_gd_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL',
    "CREATE TABLE certificate_publications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        school_id BIGINT UNSIGNED NOT NULL,
        term_id BIGINT UNSIGNED NOT NULL,
        class_id BIGINT UNSIGNED NOT NULL,
        published_by BIGINT UNSIGNED NULL,
        published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cp (term_id, class_id),
        CONSTRAINT fk_cpub_term FOREIGN KEY (school_id, term_id) REFERENCES academic_terms (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_cpub_class FOREIGN KEY (school_id, class_id) REFERENCES classes (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_cpub_user FOREIGN KEY (published_by) REFERENCES users (id) ON DELETE SET NULL
    ) $table",
];
