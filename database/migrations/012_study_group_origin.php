<?php

declare(strict_types=1);

/**
 * Where a study group came from: made by hand ('manual'), from a lesson shared by several
 * classes ('shared') or from a class split between several teachers ('split'). Used to filter
 * the list of groups. Groups created automatically before this column existed are recognised
 * by their note: one linked lesson = a split class, several = a shared lesson.
 */
return [
    "ALTER TABLE study_groups ADD COLUMN origin VARCHAR(10) NOT NULL DEFAULT 'manual' AFTER subject_id",
    "UPDATE study_groups g SET g.origin = CASE
        WHEN (SELECT COUNT(*) FROM teaching_assignments ta WHERE ta.study_group_id = g.id) > 1 THEN 'shared' ELSE 'split' END
      WHERE g.notes LIKE '%تلقائي%' OR g.notes LIKE '%אוטומטית%'",
];
