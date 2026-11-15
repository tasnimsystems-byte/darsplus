<?php

declare(strict_types=1);

/**
 * Stage E: interventions, staff development, observations, meetings, tasks,
 * guardians and parent contacts. Conventions as in 002/003.
 */

$table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$ts = 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
$id = 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL';
$big = 'BIGINT UNSIGNED';
$year = 'FOREIGN KEY (school_id, academic_year_id) REFERENCES academic_years (school_id, id)';
$stamps = "created_by $big NULL, updated_by $big NULL";
$student = 'FOREIGN KEY (school_id, student_id) REFERENCES students (school_id, id)';
$teacher = 'FOREIGN KEY (school_id, teacher_id) REFERENCES teachers (school_id, id)';
$user = static fn (string $column): string => "FOREIGN KEY ($column) REFERENCES users (id) ON DELETE SET NULL";

return [
    // ---- Interventions ----------------------------------------------------------
    "CREATE TABLE student_interventions (
        $id, academic_year_id $big NOT NULL, student_id $big NOT NULL, subject_id $big NULL, risk_indication_id $big NULL,
        difficulty_area VARCHAR(150) NOT NULL, situation_description TEXT NULL, baseline TEXT NULL,
        improvement_goal TEXT NULL, success_measure TEXT NULL, owner_user_id $big NULL,
        start_date DATE NOT NULL, review_date DATE NULL, status VARCHAR(12) NOT NULL DEFAULT 'active',
        recommendations TEXT NULL, $stamps, $ts, deleted_at DATETIME NULL,
        UNIQUE KEY uq_si_school_id (school_id, id), KEY idx_si_student (student_id, deleted_at), KEY idx_si_owner (owner_user_id, status),
        CONSTRAINT fk_si_year $year ON DELETE RESTRICT,
        CONSTRAINT fk_si_student $student ON DELETE RESTRICT,
        CONSTRAINT fk_si_subject FOREIGN KEY (school_id, subject_id) REFERENCES subjects (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_si_indication FOREIGN KEY (risk_indication_id) REFERENCES risk_indications (id) ON DELETE SET NULL,
        CONSTRAINT fk_si_owner {$user('owner_user_id')}
    ) $table",

    "CREATE TABLE intervention_actions (
        $id, intervention_id $big NOT NULL,
        description VARCHAR(255) NOT NULL, owner_user_id $big NULL, due_date DATE NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'open', completed_at DATETIME NULL, $ts,
        KEY idx_ia_owner (owner_user_id, status),
        CONSTRAINT fk_ia_intervention FOREIGN KEY (school_id, intervention_id) REFERENCES student_interventions (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_ia_owner {$user('owner_user_id')}
    ) $table",

    "CREATE TABLE intervention_reviews (
        $id, intervention_id $big NOT NULL,
        review_date DATE NOT NULL, findings TEXT NOT NULL, progress_rating VARCHAR(10) NOT NULL, next_steps TEXT NULL,
        created_by $big NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_ir_intervention FOREIGN KEY (school_id, intervention_id) REFERENCES student_interventions (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_ir_user {$user('created_by')}
    ) $table",

    // ---- Staff development --------------------------------------------------------
    "CREATE TABLE teacher_goals (
        $id, academic_year_id $big NOT NULL, teacher_id $big NOT NULL,
        title VARCHAR(190) NOT NULL, success_measure TEXT NULL, due_date DATE NULL, status VARCHAR(12) NOT NULL DEFAULT 'open', $ts,
        KEY idx_tg_teacher (teacher_id, academic_year_id),
        CONSTRAINT fk_tg_year $year ON DELETE CASCADE,
        CONSTRAINT fk_tg_teacher $teacher ON DELETE CASCADE
    ) $table",

    "CREATE TABLE teacher_trainings (
        $id, teacher_id $big NOT NULL,
        title VARCHAR(190) NOT NULL, provider VARCHAR(150) NULL, hours SMALLINT UNSIGNED NULL, start_date DATE NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'planned', $ts,
        KEY idx_tt_teacher (teacher_id),
        CONSTRAINT fk_tt_teacher $teacher ON DELETE CASCADE
    ) $table",

    // The most sensitive staff data: visible to the observer, to authorised management, and to the
    // teacher only once shared_with_teacher is set.
    "CREATE TABLE observations (
        $id, academic_year_id $big NOT NULL, teacher_id $big NOT NULL, observer_user_id $big NULL,
        record_type VARCHAR(20) NOT NULL, observed_on DATE NOT NULL,
        strengths TEXT NULL, areas_for_growth TEXT NULL, feedback TEXT NULL,
        shared_with_teacher TINYINT(1) NOT NULL DEFAULT 0, $stamps, $ts, deleted_at DATETIME NULL,
        UNIQUE KEY uq_ob_school_id (school_id, id), KEY idx_ob_teacher (teacher_id, academic_year_id),
        CONSTRAINT fk_ob_year $year ON DELETE RESTRICT,
        CONSTRAINT fk_ob_teacher $teacher ON DELETE RESTRICT,
        CONSTRAINT fk_ob_observer {$user('observer_user_id')}
    ) $table",

    "CREATE TABLE observation_recommendations (
        $id, observation_id $big NOT NULL,
        recommendation VARCHAR(500) NOT NULL, follow_up_date DATE NULL, status VARCHAR(10) NOT NULL DEFAULT 'open',
        follow_up_note VARCHAR(500) NULL, $ts,
        CONSTRAINT fk_or_observation FOREIGN KEY (school_id, observation_id) REFERENCES observations (school_id, id) ON DELETE CASCADE
    ) $table",

    // ---- Meetings -------------------------------------------------------------------
    "CREATE TABLE meetings (
        $id, academic_year_id $big NOT NULL, meeting_type VARCHAR(20) NOT NULL, title VARCHAR(190) NOT NULL,
        meeting_date DATE NOT NULL, start_time TIME NULL, location VARCHAR(120) NULL,
        organizer_user_id $big NULL, student_id $big NULL,
        agenda TEXT NULL, minutes LONGTEXT NULL, status VARCHAR(10) NOT NULL DEFAULT 'planned', $stamps, $ts, deleted_at DATETIME NULL,
        UNIQUE KEY uq_mt_school_id (school_id, id), KEY idx_mt_date (school_id, meeting_date),
        CONSTRAINT fk_mt_year $year ON DELETE RESTRICT,
        CONSTRAINT fk_mt_student $student ON DELETE RESTRICT,
        CONSTRAINT fk_mt_organizer {$user('organizer_user_id')}
    ) $table",

    "CREATE TABLE meeting_participants (
        $id, meeting_id $big NOT NULL, user_id $big NOT NULL, attendance VARCHAR(10) NOT NULL DEFAULT 'invited',
        UNIQUE KEY uq_mp (meeting_id, user_id), KEY idx_mp_user (user_id),
        CONSTRAINT fk_mp_meeting FOREIGN KEY (school_id, meeting_id) REFERENCES meetings (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_mp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) $table",

    "CREATE TABLE meeting_decisions (
        $id, meeting_id $big NOT NULL,
        decision TEXT NOT NULL, owner_user_id $big NULL, due_date DATE NULL, status VARCHAR(10) NOT NULL DEFAULT 'open', $ts,
        UNIQUE KEY uq_md_school_id (school_id, id), KEY idx_md_status (school_id, status),
        CONSTRAINT fk_md_meeting FOREIGN KEY (school_id, meeting_id) REFERENCES meetings (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_md_owner {$user('owner_user_id')}
    ) $table",

    // ---- Tasks ------------------------------------------------------------------------
    // source_type/source_id point at what produced the task (a meeting decision, …); integrity is kept in code.
    "CREATE TABLE tasks (
        $id, title VARCHAR(190) NOT NULL, description TEXT NULL,
        assignee_user_id $big NULL, assigned_by $big NULL, due_date DATE NULL,
        priority VARCHAR(10) NOT NULL DEFAULT 'normal', status VARCHAR(12) NOT NULL DEFAULT 'open',
        source_type VARCHAR(40) NULL, source_id $big NULL, completed_at DATETIME NULL, $stamps, $ts, deleted_at DATETIME NULL,
        UNIQUE KEY uq_tk_school_id (school_id, id), KEY idx_tk_assignee (assignee_user_id, status, due_date),
        KEY idx_tk_source (source_type, source_id),
        CONSTRAINT fk_tk_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT,
        CONSTRAINT fk_tk_assignee {$user('assignee_user_id')},
        CONSTRAINT fk_tk_assigner {$user('assigned_by')}
    ) $table",

    "CREATE TABLE task_comments (
        $id, task_id $big NOT NULL, user_id $big NULL, body TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_tc_task FOREIGN KEY (school_id, task_id) REFERENCES tasks (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_tc_user {$user('user_id')}
    ) $table",

    // ---- Parents ------------------------------------------------------------------------
    "CREATE TABLE guardians (
        $id, student_id $big NOT NULL,
        full_name VARCHAR(150) NOT NULL, relation VARCHAR(40) NULL, phone VARCHAR(30) NULL, email VARCHAR(190) NULL,
        is_primary TINYINT(1) NOT NULL DEFAULT 0, $ts,
        UNIQUE KEY uq_gd_school_id (school_id, id), KEY idx_gd_student (student_id),
        CONSTRAINT fk_gd_student $student ON DELETE CASCADE
    ) $table",

    // internal_notes never leave the staff; shared_summary is the only text meant for the family.
    "CREATE TABLE parent_contacts (
        $id, academic_year_id $big NOT NULL, student_id $big NOT NULL, guardian_id $big NULL, staff_user_id $big NULL,
        contact_type VARCHAR(12) NOT NULL, contacted_on DATE NOT NULL,
        internal_notes TEXT NULL, shared_summary TEXT NULL, decisions TEXT NULL,
        follow_up_date DATE NULL, follow_up_status VARCHAR(10) NOT NULL DEFAULT 'none', $stamps, $ts, deleted_at DATETIME NULL,
        UNIQUE KEY uq_pc_school_id (school_id, id), KEY idx_pc_student (student_id, contacted_on),
        CONSTRAINT fk_pc_year $year ON DELETE RESTRICT,
        CONSTRAINT fk_pc_student $student ON DELETE RESTRICT,
        CONSTRAINT fk_pc_guardian FOREIGN KEY (school_id, guardian_id) REFERENCES guardians (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_pc_staff {$user('staff_user_id')}
    ) $table",
];
