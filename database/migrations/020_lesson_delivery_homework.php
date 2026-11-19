<?php

declare(strict_types=1);

/**
 * How a lesson was given (in class, or remotely through an online meeting), the homework's due date,
 * and what is attached to the homework: files (stored outside the web root under a random name and
 * served only after an access check) and links. A study group's lesson is recorded on each class's
 * lesson session, so every attachment is copied to each of them under one share_key.
 */
$table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$big = 'BIGINT UNSIGNED';

return [
    "ALTER TABLE lesson_sessions
        ADD COLUMN delivery VARCHAR(10) NOT NULL DEFAULT 'in_person' AFTER status,
        ADD COLUMN homework_due DATE NULL AFTER homework",
    "CREATE TABLE lesson_attachments (
        id $big AUTO_INCREMENT PRIMARY KEY, school_id $big NOT NULL, lesson_session_id $big NOT NULL,
        share_key CHAR(16) NOT NULL, kind VARCHAR(5) NOT NULL, title VARCHAR(190) NOT NULL,
        url VARCHAR(500) NULL, stored_name CHAR(40) NULL, original_name VARCHAR(255) NULL,
        mime_type VARCHAR(120) NULL, size_bytes INT UNSIGNED NULL,
        created_by $big NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_la_session (lesson_session_id), KEY idx_la_share (share_key), KEY idx_la_stored (stored_name),
        CONSTRAINT fk_la_session FOREIGN KEY (school_id, lesson_session_id) REFERENCES lesson_sessions (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_la_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
    ) $table",
];
