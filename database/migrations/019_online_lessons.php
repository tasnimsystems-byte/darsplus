<?php

declare(strict_types=1);

/**
 * Online lessons: a live meeting opened by the teacher for one lesson (a lesson session), and who
 * entered it. Media is never stored here and nothing is recorded; a row of participants only tells
 * the teacher who joined, to help with the attendance of the lesson, and whom the teacher allowed
 * to share their screen (kept across reconnections and when the microphones are locked).
 */
$table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$big = 'BIGINT UNSIGNED';

return [
    "CREATE TABLE online_meetings (
        id $big AUTO_INCREMENT PRIMARY KEY, school_id $big NOT NULL, lesson_session_id $big NOT NULL,
        room_name VARCHAR(64) NOT NULL, mics_locked TINYINT(1) NOT NULL DEFAULT 0,
        started_by $big NULL, started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ended_at DATETIME NULL,
        UNIQUE KEY uq_om_room (room_name), UNIQUE KEY uq_om_school_id (school_id, id), KEY idx_om_session (lesson_session_id, ended_at),
        CONSTRAINT fk_om_session FOREIGN KEY (school_id, lesson_session_id) REFERENCES lesson_sessions (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_om_user FOREIGN KEY (started_by) REFERENCES users (id) ON DELETE SET NULL
    ) $table",
    "CREATE TABLE online_meeting_participants (
        id $big AUTO_INCREMENT PRIMARY KEY, school_id $big NOT NULL, meeting_id $big NOT NULL, user_id $big NOT NULL,
        student_id $big NULL, role VARCHAR(10) NOT NULL, joins SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        can_share_screen TINYINT(1) NOT NULL DEFAULT 0,
        first_joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_omp (meeting_id, user_id), KEY idx_omp_student (student_id),
        CONSTRAINT fk_omp_meeting FOREIGN KEY (school_id, meeting_id) REFERENCES online_meetings (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_omp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
        CONSTRAINT fk_omp_student FOREIGN KEY (school_id, student_id) REFERENCES students (school_id, id) ON DELETE CASCADE
    ) $table",
];
