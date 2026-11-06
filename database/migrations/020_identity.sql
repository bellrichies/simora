CREATE TABLE users (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 normalized_email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 display_name VARCHAR(100) NOT NULL,
 email_verified_at DATETIME(6) NULL,
 status ENUM('active','disabled') NOT NULL DEFAULT 'active',
 created_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_users_email (normalized_email)
) ENGINE=InnoDB;
CREATE TABLE roles (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB;
CREATE TABLE permissions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB;
CREATE TABLE user_roles (
 user_id BIGINT UNSIGNED NOT NULL,
 role_id BIGINT UNSIGNED NOT NULL,
 assigned_by BIGINT UNSIGNED NULL,
 assigned_at DATETIME(6) NOT NULL,
 PRIMARY KEY (user_id,role_id),
 CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id),
 CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id),
 CONSTRAINT fk_user_roles_actor FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE role_permissions (
 role_id BIGINT UNSIGNED NOT NULL,
 permission_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY (role_id,permission_id),
 CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id),
 CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id)
) ENGINE=InnoDB;
CREATE TABLE sessions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 token_hash BINARY(32) NOT NULL,
 created_at DATETIME(6) NOT NULL,
 expires_at DATETIME(6) NOT NULL,
 revoked_at DATETIME(6) NULL,
 UNIQUE KEY uq_sessions_token (token_hash),
 KEY ix_sessions_owner (user_id,expires_at),
 CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE email_verification_tokens (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 token_hash BINARY(32) NOT NULL,
 expires_at DATETIME(6) NOT NULL,
 used_at DATETIME(6) NULL,
 UNIQUE KEY uq_verification_token (token_hash),
 CONSTRAINT fk_verification_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE password_resets (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 token_hash BINARY(32) NOT NULL,
 expires_at DATETIME(6) NOT NULL,
 used_at DATETIME(6) NULL,
 UNIQUE KEY uq_reset_token (token_hash),
 CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE audit_logs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 actor_user_id BIGINT UNSIGNED NULL,
 operation VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 object_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 object_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 correlation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 occurred_at DATETIME(6) NOT NULL,
 KEY ix_audit_object (object_type,object_id,occurred_at),
 CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE settings (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 setting_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 setting_value VARCHAR(255) NOT NULL,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 updated_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_settings_owner_key (user_id,setting_key),
 CONSTRAINT fk_settings_owner FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
