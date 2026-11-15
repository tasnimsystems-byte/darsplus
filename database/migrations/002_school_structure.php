<?php

declare(strict_types=1);

/**
 * Stage C: school structure — terms, grade levels, subjects, rooms, teachers,
 * classes, students, enrolments, teaching assignments, staff positions, calendar.
 *
 * Every table carries school_id, and relations between them use composite
 * foreign keys (school_id, x_id) → parent (school_id, id). The database itself
 * therefore refuses to link, say, a student of one school to a class of another.
 */

$table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$timestamps = 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
$school = 'school_id BIGINT UNSIGNED NOT NULL';

return [
    'ALTER TABLE academic_years ADD UNIQUE KEY uq_years_school_id (school_id, id)',

    // Whether holders of a role see the whole school or only what they are assigned to.
    "ALTER TABLE roles ADD COLUMN scope_mode VARCHAR(10) NOT NULL DEFAULT 'assigned' AFTER level",
    "UPDATE roles SET scope_mode = 'all' WHERE is_system = 1
        AND code IN ('system_admin', 'principal', 'vice_principal', 'pedagogical_coordinator', 'counselor')",

    "CREATE TABLE academic_terms (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        $school,
        academic_year_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(60) NOT NULL,
        sort_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        $timestamps,
        UNIQUE KEY uq_terms_year_name (academic_year_id, name),
        UNIQUE KEY uq_terms_school_id (school_id, id),
        CONSTRAINT fk_terms_year FOREIGN KEY (school_id, academic_year_id)
            REFERENCES academic_years (school_id, id) ON DELETE CASCADE
    ) $table",

    "CREATE TABLE grade_levels (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        $school,
        name VARCHAR(40) NOT NULL,
        level_number TINYINT UNSIGNED NOT NULL,
        $timestamps,
        UNIQUE KEY uq_levels_name (school_id, name),
        UNIQUE KEY uq_levels_number (school_id, level_number),
        UNIQUE KEY uq_levels_school_id (school_id, id),
        CONSTRAINT fk_levels_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT
    ) $table",

    "CREATE TABLE subjects (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        $school,
        name VARCHAR(100) NOT NULL,
        code VARCHAR(20) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        $timestamps,
        UNIQUE KEY uq_subjects_name (school_id, name),
        UNIQUE KEY uq_subjects_school_id (school_id, id),
        CONSTRAINT fk_subjects_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT
    ) $table",

    "CREATE TABLE rooms (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        $school,
        name VARCHAR(60) NOT NULL,
        capacity SMALLINT UNSIGNED NULL,
        $timestamps,
        UNIQUE KEY uq_rooms_name (school_id, name),
        UNIQUE KEY uq_rooms_school_id (school_id, id),
        CONSTRAINT fk_rooms_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT
    ) $table",

    // Pedagogical management data only: no salary, family, medical or disciplinary information.
    "CREATE TABLE teachers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        $school,
        user_id BIGINT UNSIGNED NULL,
        first_name VARCHAR(80) NOT NULL,
        last_name VARCHAR(80) NOT NULL,
        staff_code VARCHAR(30) NULL,
        phone VARCHAR(30) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by BIGINT UNSIGNED NULL,
        updated_by BIGINT UNSIGNED NULL,
        $timestamps,
        deleted_at DATETIME NULL,
        UNIQUE KEY uq_teachers_code (school_id, staff_code),
        UNIQUE KEY uq_teachers_user (school_id, user_id),
        UNIQUE KEY uq_teachers_school_id (school_id, id),
        KEY idx_teachers_name (school_id, last_name, first_name),
        CONSTRAINT fk_teachers_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT,
        CONSTRAINT fk_teachers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
        CONSTRAINT fk_teachers_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
        CONSTRAINT fk_teachers_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
    ) $table",

    "CREATE TABLE teacher_subjects (
        $school,
        teacher_id BIGINT UNSIGNED NOT NULL,
        subject_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (teacher_id, subject_id),
        CONSTRAINT fk_ts_teacher FOREIGN KEY (school_id, teacher_id) REFERENCES teachers (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_ts_subject FOREIGN KEY (school_id, subject_id) REFERENCES subjects (school_id, id) ON DELETE CASCADE
    ) $table",

    // A class belongs to one academic year: 'ז1 תשפ\"ז' and 'ז1 תשפ\"ח' are two rows, so history keeps itself.
    "CREATE TABLE classes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        $school,
        academic_year_id BIGINT UNSIGNED NOT NULL,
        grade_level_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(40) NOT NULL,
        homeroom_teacher_id BIGINT UNSIGNED NULL,
        room_id BIGINT UNSIGNED NULL,
        $timestamps,
        UNIQUE KEY uq_classes_year_name (academic_year_id, name),
        UNIQUE KEY uq_classes_school_id (school_id, id),
        KEY idx_classes_homeroom (homeroom_teacher_id),
        CONSTRAINT fk_classes_year FOREIGN KEY (school_id, academic_year_id) REFERENCES academic_years (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_classes_level FOREIGN KEY (school_id, grade_level_id) REFERENCES grade_levels (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_classes_teacher FOREIGN KEY (school_id, homeroom_teacher_id) REFERENCES teachers (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_classes_room FOREIGN KEY (school_id, room_id) REFERENCES rooms (school_id, id) ON DELETE RESTRICT
    ) $table",

    // Data minimisation: an internal code instead of a national ID; no address, health or socio-economic data.
    "CREATE TABLE students (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        $school,
        student_code VARCHAR(30) NOT NULL,
        first_name VARCHAR(80) NOT NULL,
        last_name VARCHAR(80) NOT NULL,
        gender CHAR(1) NULL,
        birth_date DATE NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'active',
        created_by BIGINT UNSIGNED NULL,
        updated_by BIGINT UNSIGNED NULL,
        $timestamps,
        deleted_at DATETIME NULL,
        UNIQUE KEY uq_students_code (school_id, student_code),
        UNIQUE KEY uq_students_school_id (school_id, id),
        KEY idx_students_name (school_id, last_name, first_name),
        CONSTRAINT fk_students_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT,
        CONSTRAINT fk_students_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
        CONSTRAINT fk_students_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
    ) $table",

    // left_on NULL = currently in the class. A move within the year closes one row and opens another.
    "CREATE TABLE class_enrollments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        $school,
        student_id BIGINT UNSIGNED NOT NULL,
        class_id BIGINT UNSIGNED NOT NULL,
        enrolled_on DATE NOT NULL,
        left_on DATE NULL,
        $timestamps,
        UNIQUE KEY uq_enrollment (student_id, class_id),
        KEY idx_enrollment_class (class_id, left_on),
        CONSTRAINT fk_enr_student FOREIGN KEY (school_id, student_id) REFERENCES students (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_enr_class FOREIGN KEY (school_id, class_id) REFERENCES classes (school_id, id) ON DELETE RESTRICT
    ) $table",

    // The axis from which a teacher's access scope is derived.
    "CREATE TABLE teaching_assignments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        $school,
        academic_year_id BIGINT UNSIGNED NOT NULL,
        teacher_id BIGINT UNSIGNED NOT NULL,
        class_id BIGINT UNSIGNED NOT NULL,
        subject_id BIGINT UNSIGNED NOT NULL,
        weekly_hours DECIMAL(4,1) NULL,
        group_name VARCHAR(50) NOT NULL DEFAULT '',
        $timestamps,
        UNIQUE KEY uq_assignment (teacher_id, class_id, subject_id, group_name),
        UNIQUE KEY uq_assignment_school_id (school_id, id),
        KEY idx_assignment_teacher (teacher_id, academic_year_id),
        KEY idx_assignment_class (class_id, subject_id),
        CONSTRAINT fk_ta_year FOREIGN KEY (school_id, academic_year_id) REFERENCES academic_years (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_ta_teacher FOREIGN KEY (school_id, teacher_id) REFERENCES teachers (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_ta_class FOREIGN KEY (school_id, class_id) REFERENCES classes (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_ta_subject FOREIGN KEY (school_id, subject_id) REFERENCES subjects (school_id, id) ON DELETE RESTRICT
    ) $table",

    "CREATE TABLE staff_positions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        $school,
        academic_year_id BIGINT UNSIGNED NOT NULL,
        teacher_id BIGINT UNSIGNED NOT NULL,
        position_type VARCHAR(30) NOT NULL,
        grade_level_id BIGINT UNSIGNED NULL,
        subject_id BIGINT UNSIGNED NULL,
        responsibilities TEXT NULL,
        $timestamps,
        KEY idx_positions_teacher (teacher_id, academic_year_id),
        CONSTRAINT fk_sp_year FOREIGN KEY (school_id, academic_year_id) REFERENCES academic_years (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_sp_teacher FOREIGN KEY (school_id, teacher_id) REFERENCES teachers (school_id, id) ON DELETE CASCADE,
        CONSTRAINT fk_sp_level FOREIGN KEY (school_id, grade_level_id) REFERENCES grade_levels (school_id, id) ON DELETE RESTRICT,
        CONSTRAINT fk_sp_subject FOREIGN KEY (school_id, subject_id) REFERENCES subjects (school_id, id) ON DELETE RESTRICT
    ) $table",

    "CREATE TABLE calendar_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        $school,
        academic_year_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(150) NOT NULL,
        event_type VARCHAR(20) NOT NULL DEFAULT 'event',
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        $timestamps,
        KEY idx_calendar_year (academic_year_id, start_date),
        CONSTRAINT fk_cal_year FOREIGN KEY (school_id, academic_year_id) REFERENCES academic_years (school_id, id) ON DELETE CASCADE
    ) $table",
];
