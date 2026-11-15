<?php

declare(strict_types=1);

/**
 * Stage D: work plan, curriculum, assessments, attendance, risk indications.
 * Same conventions as 002: school_id everywhere, composite foreign keys.
 */

$table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$ts = 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
$id = 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL';
$big = 'BIGINT UNSIGNED';
$year = 'FOREIGN KEY (school_id, academic_year_id) REFERENCES academic_years (school_id, id)';
$stamps = "created_by $big NULL, updated_by $big NULL";

return [
    // ---- Work plan ------------------------------------------------------------
    "CREATE TABLE work_plans (
        $id, academic_year_id $big NOT NULL,
        vision TEXT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'draft',
        approved_by $big NULL, approved_at DATETIME NULL, $ts,
        UNIQUE KEY uq_wp_year (academic_year_id), UNIQUE KEY uq_wp_school_id (school_id, id),
        CONSTRAINT fk_wp_year $year ON DELETE RESTRICT,
        CONSTRAINT fk_wp_approver FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL
    ) $table",

    "CREATE TABLE plan_objectives (
        $id, work_plan_id $big NOT NULL,
        title VARCHAR(190) NOT NULL, description TEXT NULL, sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1, $ts,
        UNIQUE KEY uq_po_school_id (school_id, id),
        CONSTRAINT fk_po_plan FOREIGN KEY (school_id, work_plan_id) REFERENCES work_plans (school_id, id) ON DELETE CASCADE
    ) $table",

    "CREATE TABLE pedagogical_domains (
        $id, name VARCHAR(100) NOT NULL, $ts,
        UNIQUE KEY uq_pd_name (school_id, name), UNIQUE KEY uq_pd_school_id (school_id, id),
        CONSTRAINT fk_pd_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT
    ) $table",

    "CREATE TABLE annual_goals (
        $id, academic_year_id $big NOT NULL, plan_objective_id $big NOT NULL, domain_id $big NULL,
        title VARCHAR(190) NOT NULL, description TEXT NULL,
        baseline_description TEXT NULL, target_description TEXT NULL, success_measure TEXT NULL,
        owner_user_id $big NULL, start_date DATE NOT NULL, end_date DATE NOT NULL,
        progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'not_started',
        effectiveness_review TEXT NULL, $stamps, $ts, deleted_at DATETIME NULL,
        UNIQUE KEY uq_goal_school_id (school_id, id), KEY idx_goal_year (academic_year_id, deleted_at),
        CONSTRAINT fk_goal_year $year ON DELETE RESTRICT,
        CONSTRAINT fk_goal_objective FOREIGN KEY (school_id, plan_objective_id) REFERENCES plan_objectives (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_goal_domain FOREIGN KEY (school_id, domain_id) REFERENCES pedagogical_domains (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_goal_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE SET NULL
    ) $table",

    "CREATE TABLE goal_indicators (
        $id, goal_id $big NOT NULL,
        name VARCHAR(190) NOT NULL, unit VARCHAR(30) NULL,
        baseline_value DECIMAL(10,2) NULL, target_value DECIMAL(10,2) NULL, current_value DECIMAL(10,2) NULL, $ts,
        CONSTRAINT fk_gi_goal FOREIGN KEY (school_id, goal_id) REFERENCES annual_goals (school_id, id) ON DELETE CASCADE
    ) $table",

    "CREATE TABLE goal_actions (
        $id, goal_id $big NOT NULL,
        title VARCHAR(190) NOT NULL, owner_user_id $big NULL, start_date DATE NULL, due_date DATE NULL,
        resources TEXT NULL, budget DECIMAL(10,2) NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'open', completed_at DATETIME NULL, $ts,
        KEY idx_ga_owner (owner_user_id, status),
        CONSTRAINT fk_ga_goal FOREIGN KEY (school_id, goal_id) REFERENCES annual_goals (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_ga_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE SET NULL
    ) $table",

    // History of progress: one row per reported update, never edited.
    "CREATE TABLE goal_progress_updates (
        $id, goal_id $big NOT NULL,
        progress_percent TINYINT UNSIGNED NOT NULL, status VARCHAR(20) NOT NULL, note TEXT NULL,
        created_by $big NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_gpu_goal FOREIGN KEY (school_id, goal_id) REFERENCES annual_goals (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_gpu_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
    ) $table",

    // ---- Curriculum -----------------------------------------------------------
    "CREATE TABLE curriculum_plans (
        $id, academic_year_id $big NOT NULL, subject_id $big NOT NULL, grade_level_id $big NOT NULL,
        class_id $big NULL, owner_teacher_id $big NULL,
        title VARCHAR(190) NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'draft', $stamps, $ts, deleted_at DATETIME NULL,
        UNIQUE KEY uq_cp_school_id (school_id, id), KEY idx_cp_year (academic_year_id, subject_id, grade_level_id),
        CONSTRAINT fk_cp_year $year ON DELETE RESTRICT,
        CONSTRAINT fk_cp_subject FOREIGN KEY (school_id, subject_id) REFERENCES subjects (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_cp_level FOREIGN KEY (school_id, grade_level_id) REFERENCES grade_levels (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_cp_class FOREIGN KEY (school_id, class_id) REFERENCES classes (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_cp_teacher FOREIGN KEY (school_id, owner_teacher_id) REFERENCES teachers (school_id, id) ON DELETE RESTRICT
    ) $table",

    "CREATE TABLE curriculum_units (
        $id, plan_id $big NOT NULL, term_id $big NULL,
        title VARCHAR(190) NOT NULL, sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1, $ts,
        UNIQUE KEY uq_cu_school_id (school_id, id),
        CONSTRAINT fk_cu_plan FOREIGN KEY (school_id, plan_id) REFERENCES curriculum_plans (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_cu_term FOREIGN KEY (school_id, term_id) REFERENCES academic_terms (school_id, id) ON DELETE RESTRICT
    ) $table",

    // planned_week_start drives the monthly and weekly views; no separate tables per time scale.
    "CREATE TABLE curriculum_topics (
        $id, unit_id $big NOT NULL, parent_topic_id $big NULL,
        title VARCHAR(190) NOT NULL,
        learning_objectives TEXT NULL, skills TEXT NULL, teaching_methods TEXT NULL, assessment_methods TEXT NULL,
        planned_week_start DATE NULL, planned_hours DECIMAL(4,1) NULL, sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1, $ts,
        UNIQUE KEY uq_ct_school_id (school_id, id),
        CONSTRAINT fk_ct_unit FOREIGN KEY (school_id, unit_id) REFERENCES curriculum_units (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_ct_parent FOREIGN KEY (school_id, parent_topic_id) REFERENCES curriculum_topics (school_id, id) ON DELETE CASCADE
    ) $table",

    // Actual teaching is recorded per teaching assignment, so parallel classes can be compared.
    "CREATE TABLE curriculum_progress (
        $id, topic_id $big NOT NULL, teaching_assignment_id $big NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'not_started', actual_date DATE NULL, actual_hours DECIMAL(4,1) NULL,
        note VARCHAR(500) NULL, updated_by $big NULL, $ts,
        UNIQUE KEY uq_cpr (topic_id, teaching_assignment_id),
        CONSTRAINT fk_cpr_topic FOREIGN KEY (school_id, topic_id) REFERENCES curriculum_topics (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_cpr_assignment FOREIGN KEY (school_id, teaching_assignment_id) REFERENCES teaching_assignments (school_id, id) ON DELETE RESTRICT
    ) $table",

    // ---- Assessment -----------------------------------------------------------
    "CREATE TABLE assessment_types (
        $id, name VARCHAR(80) NOT NULL, category VARCHAR(20) NOT NULL, is_numeric TINYINT(1) NOT NULL DEFAULT 1, $ts,
        UNIQUE KEY uq_at_name (school_id, name), UNIQUE KEY uq_at_school_id (school_id, id),
        CONSTRAINT fk_at_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT
    ) $table",

    // Every NOT NULL key below is what guarantees that no score exists without subject, term and type.
    "CREATE TABLE assessments (
        $id, academic_year_id $big NOT NULL, teaching_assignment_id $big NOT NULL, term_id $big NOT NULL, type_id $big NOT NULL,
        title VARCHAR(150) NOT NULL, assessment_date DATE NOT NULL,
        max_score DECIMAL(6,1) NOT NULL DEFAULT 100, weight DECIMAL(5,2) NOT NULL DEFAULT 1,
        status VARCHAR(10) NOT NULL DEFAULT 'draft', published_at DATETIME NULL, $stamps, $ts, deleted_at DATETIME NULL,
        UNIQUE KEY uq_as_school_id (school_id, id),
        KEY idx_as_assignment (teaching_assignment_id, term_id), KEY idx_as_term (term_id, type_id),
        CONSTRAINT fk_as_year $year ON DELETE RESTRICT,
        CONSTRAINT fk_as_assignment FOREIGN KEY (school_id, teaching_assignment_id) REFERENCES teaching_assignments (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_as_term FOREIGN KEY (school_id, term_id) REFERENCES academic_terms (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_as_type FOREIGN KEY (school_id, type_id) REFERENCES assessment_types (school_id, id) ON DELETE RESTRICT
    ) $table",

    // An absent student has score NULL and is left out of averages (never counted as zero).
    "CREATE TABLE assessment_results (
        $id, assessment_id $big NOT NULL, student_id $big NOT NULL,
        score DECIMAL(6,1) NULL, result_status VARCHAR(10) NOT NULL DEFAULT 'graded',
        verbal_evaluation VARCHAR(255) NULL, comment VARCHAR(500) NULL, updated_by $big NULL, $ts,
        UNIQUE KEY uq_ar (assessment_id, student_id), KEY idx_ar_student (student_id),
        CONSTRAINT fk_ar_assessment FOREIGN KEY (school_id, assessment_id) REFERENCES assessments (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_ar_student FOREIGN KEY (school_id, student_id) REFERENCES students (school_id, id) ON DELETE RESTRICT
    ) $table",

    // ---- Attendance (per lesson) ----------------------------------------------
    "CREATE TABLE lesson_sessions (
        $id, teaching_assignment_id $big NOT NULL,
        lesson_date DATE NOT NULL, period_number TINYINT UNSIGNED NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'held', recorded_by $big NULL, $ts,
        UNIQUE KEY uq_ls (teaching_assignment_id, lesson_date, period_number),
        UNIQUE KEY uq_ls_school_id (school_id, id), KEY idx_ls_date (school_id, lesson_date),
        CONSTRAINT fk_ls_assignment FOREIGN KEY (school_id, teaching_assignment_id) REFERENCES teaching_assignments (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_ls_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
    ) $table",

    // Exceptions only: a student with no row in a held session was present.
    "CREATE TABLE attendance_records (
        $id, lesson_session_id $big NOT NULL, student_id $big NOT NULL,
        status VARCHAR(10) NOT NULL, note VARCHAR(255) NULL, $ts,
        UNIQUE KEY uq_att (lesson_session_id, student_id), KEY idx_att_student (student_id, status),
        CONSTRAINT fk_att_session FOREIGN KEY (school_id, lesson_session_id) REFERENCES lesson_sessions (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_att_student FOREIGN KEY (school_id, student_id) REFERENCES students (school_id, id) ON DELETE RESTRICT
    ) $table",

    // ---- Early identification ---------------------------------------------------
    "CREATE TABLE risk_criteria (
        $id, name VARCHAR(150) NOT NULL, criterion_type VARCHAR(20) NOT NULL,
        threshold DECIMAL(5,1) NULL, min_count SMALLINT UNSIGNED NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, $ts,
        UNIQUE KEY uq_rc_name (school_id, name), UNIQUE KEY uq_rc_school_id (school_id, id),
        CONSTRAINT fk_rc_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT
    ) $table",

    // An indication is a prompt for a human review, never a decision. `evidence` keeps the values that triggered it.
    "CREATE TABLE risk_indications (
        $id, student_id $big NOT NULL, criterion_id $big NOT NULL, term_id $big NOT NULL,
        evidence LONGTEXT NOT NULL, review_status VARCHAR(15) NOT NULL DEFAULT 'new',
        reviewed_by $big NULL, reviewed_at DATETIME NULL, review_note TEXT NULL, $ts,
        UNIQUE KEY uq_ri (student_id, criterion_id, term_id), KEY idx_ri_status (school_id, review_status, term_id),
        CONSTRAINT fk_ri_student FOREIGN KEY (school_id, student_id) REFERENCES students (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_ri_criterion FOREIGN KEY (school_id, criterion_id) REFERENCES risk_criteria (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_ri_term FOREIGN KEY (school_id, term_id) REFERENCES academic_terms (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_ri_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
    ) $table",
];
