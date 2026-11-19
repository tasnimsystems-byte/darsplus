<?php

declare(strict_types=1);

/**
 * Lesson record: what was taught in a lesson, the homework given and the teacher's notes,
 * optionally tied to a topic of the curriculum plan. Kept on the lesson session itself.
 * A deleted curriculum topic leaves the lesson record and only clears the link.
 */
return [
    'ALTER TABLE lesson_sessions
        ADD COLUMN topic VARCHAR(255) NULL AFTER status,
        ADD COLUMN curriculum_topic_id BIGINT UNSIGNED NULL AFTER topic,
        ADD COLUMN homework TEXT NULL AFTER curriculum_topic_id,
        ADD COLUMN lesson_notes TEXT NULL AFTER homework,
        ADD CONSTRAINT fk_ls_topic FOREIGN KEY (curriculum_topic_id) REFERENCES curriculum_topics (id) ON DELETE SET NULL',
];
