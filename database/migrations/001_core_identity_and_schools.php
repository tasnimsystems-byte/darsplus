<?php

declare(strict_types=1);

/**
 * Stage B: schools, identity, RBAC, audit, notifications, documents.
 * Compatible with MariaDB 10.4+ and MySQL 8+. JSON payloads are stored as LONGTEXT
 * (MariaDB's JSON is an alias for it) and are never filtered in SQL.
 */

$table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$timestamps = 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';

return [
    "CREATE TABLE schools (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        code VARCHAR(30) NOT NULL,
        default_locale VARCHAR(5) NOT NULL DEFAULT 'he',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        $timestamps,
        deleted_at DATETIME NULL,
        UNIQUE KEY uq_schools_code (code)
    ) $table",

    "CREATE TABLE users (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(60) NOT NULL,
        email VARCHAR(190) NULL,
        password_hash VARCHAR(255) NOT NULL,
        full_name VARCHAR(150) NOT NULL,
        locale VARCHAR(5) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        must_change_password TINYINT(1) NOT NULL DEFAULT 0,
        last_login_at DATETIME NULL,
        created_by BIGINT UNSIGNED NULL,
        updated_by BIGINT UNSIGNED NULL,
        $timestamps,
        deleted_at DATETIME NULL,
        UNIQUE KEY uq_users_username (username),
        UNIQUE KEY uq_users_email (email),
        CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
        CONSTRAINT fk_users_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
    ) $table",

    "CREATE TABLE academic_years (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        school_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(30) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        is_current TINYINT(1) NOT NULL DEFAULT 0,
        is_locked TINYINT(1) NOT NULL DEFAULT 0,
        $timestamps,
        UNIQUE KEY uq_years_school_name (school_id, name),
        CONSTRAINT fk_years_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT
    ) $table",

    // school_id NULL = system role shared by all schools; otherwise a custom role of that school.
    "CREATE TABLE roles (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        school_id BIGINT UNSIGNED NULL,
        code VARCHAR(60) NOT NULL,
        name VARCHAR(100) NOT NULL,
        level VARCHAR(10) NOT NULL DEFAULT 'school',
        is_system TINYINT(1) NOT NULL DEFAULT 0,
        $timestamps,
        UNIQUE KEY uq_roles_school_code (school_id, code),
        KEY idx_roles_system (is_system, code),
        CONSTRAINT fk_roles_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE
    ) $table",

    "CREATE TABLE permissions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        module VARCHAR(40) NOT NULL,
        action VARCHAR(20) NOT NULL,
        code VARCHAR(61) NOT NULL,
        UNIQUE KEY uq_permissions_code (code)
    ) $table",

    "CREATE TABLE role_permissions (
        role_id BIGINT UNSIGNED NOT NULL,
        permission_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (role_id, permission_id),
        CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
        CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
    ) $table",

    // school_id NULL = network-level assignment.
    "CREATE TABLE user_roles (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        role_id BIGINT UNSIGNED NOT NULL,
        school_id BIGINT UNSIGNED NULL,
        academic_year_id BIGINT UNSIGNED NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ur_user_school (user_id, school_id),
        KEY idx_ur_school (school_id),
        CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
        CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT,
        CONSTRAINT fk_ur_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
        CONSTRAINT fk_ur_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE,
        CONSTRAINT fk_ur_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
    ) $table",

    // Manual access scope on top of the scope derived from teaching assignments (stage C).
    "CREATE TABLE user_scopes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        school_id BIGINT UNSIGNED NOT NULL,
        academic_year_id BIGINT UNSIGNED NULL,
        scope_type VARCHAR(20) NOT NULL,
        scope_id BIGINT UNSIGNED NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_us_user (user_id, school_id, academic_year_id),
        CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
        CONSTRAINT fk_us_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
        CONSTRAINT fk_us_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE,
        CONSTRAINT fk_us_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
    ) $table",

    "CREATE TABLE login_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(60) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        success TINYINT(1) NOT NULL,
        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_la_username (username, attempted_at),
        KEY idx_la_ip (ip_address, attempted_at)
    ) $table",

    // Append-only: the application never updates or deletes rows here.
    "CREATE TABLE audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        school_id BIGINT UNSIGNED NULL,
        user_id BIGINT UNSIGNED NULL,
        action VARCHAR(30) NOT NULL,
        entity_type VARCHAR(60) NOT NULL,
        entity_id BIGINT UNSIGNED NULL,
        old_values LONGTEXT NULL,
        new_values LONGTEXT NULL,
        ip_address VARCHAR(45) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_audit_school_time (school_id, created_at),
        KEY idx_audit_entity (entity_type, entity_id),
        KEY idx_audit_user (user_id, created_at),
        CONSTRAINT fk_audit_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE SET NULL,
        CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
    ) $table",

    "CREATE TABLE notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        school_id BIGINT UNSIGNED NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        type VARCHAR(40) NOT NULL,
        title VARCHAR(255) NOT NULL,
        body TEXT NULL,
        link VARCHAR(255) NULL,
        read_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_notif_user (user_id, read_at),
        CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
        CONSTRAINT fk_notif_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE
    ) $table",

    "CREATE TABLE school_settings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        school_id BIGINT UNSIGNED NOT NULL,
        setting_key VARCHAR(80) NOT NULL,
        setting_value TEXT NULL,
        $timestamps,
        UNIQUE KEY uq_settings (school_id, setting_key),
        CONSTRAINT fk_settings_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE
    ) $table",

    "CREATE TABLE documents (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        school_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(190) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name CHAR(40) NOT NULL,
        mime_type VARCHAR(120) NOT NULL,
        size_bytes INT UNSIGNED NOT NULL,
        sha256 CHAR(64) NOT NULL,
        category VARCHAR(40) NOT NULL DEFAULT 'other',
        visibility VARCHAR(20) NOT NULL DEFAULT 'restricted',
        uploaded_by BIGINT UNSIGNED NULL,
        $timestamps,
        deleted_at DATETIME NULL,
        UNIQUE KEY uq_documents_stored (stored_name),
        KEY idx_documents_school (school_id, deleted_at),
        CONSTRAINT fk_documents_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT,
        CONSTRAINT fk_documents_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
    ) $table",

    "CREATE TABLE document_links (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        document_id BIGINT UNSIGNED NOT NULL,
        entity_type VARCHAR(60) NOT NULL,
        entity_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_document_link (document_id, entity_type, entity_id),
        KEY idx_document_entity (entity_type, entity_id),
        CONSTRAINT fk_dl_document FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE
    ) $table",
];
