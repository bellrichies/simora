CREATE TABLE chat_rooms (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 platform_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 league_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
 visibility ENUM('members') NOT NULL DEFAULT 'members',
 status ENUM('closed','open') NOT NULL DEFAULT 'closed',
 policy_reference VARCHAR(255) NOT NULL,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 UNIQUE KEY uq_chat_room_public (public_id),
 UNIQUE KEY uq_chat_room_code (code),
 CONSTRAINT fk_chat_room_platform FOREIGN KEY (platform_id) REFERENCES platforms(id),
 CONSTRAINT fk_chat_room_league_scope FOREIGN KEY (platform_id,league_id) REFERENCES virtual_leagues(platform_id,id),
 CONSTRAINT chk_chat_room_scope CHECK (league_id IS NULL OR platform_id IS NOT NULL)
) ENGINE=InnoDB;
CREATE TABLE chat_memberships (
 room_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 role ENUM('member') NOT NULL DEFAULT 'member',
 status ENUM('active','banned') NOT NULL DEFAULT 'active',
 muted_until DATETIME(6) NULL,
 consent_reference VARCHAR(255) NOT NULL,
 created_at DATETIME(6) NOT NULL,
 PRIMARY KEY (room_id,user_id),
 CONSTRAINT fk_chat_membership_room FOREIGN KEY (room_id) REFERENCES chat_rooms(id),
 CONSTRAINT fk_chat_membership_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE chat_messages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 room_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 body TEXT NOT NULL,
 status ENUM('pending','published','hidden') NOT NULL DEFAULT 'pending',
 request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 moderator_user_id BIGINT UNSIGNED NULL,
 moderation_reason VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 moderated_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_chat_message_public (public_id),
 UNIQUE KEY uq_chat_message_request (user_id,request_key),
 KEY ix_chat_message_room (room_id,status,id),
 CONSTRAINT fk_chat_message_membership FOREIGN KEY (room_id,user_id) REFERENCES chat_memberships(room_id,user_id),
 CONSTRAINT fk_chat_message_moderator FOREIGN KEY (moderator_user_id) REFERENCES users(id),
 CONSTRAINT chk_chat_message_moderator CHECK (status<>'published' OR (moderator_user_id IS NOT NULL AND moderator_user_id<>user_id AND moderated_at IS NOT NULL))
) ENGINE=InnoDB;
INSERT INTO permissions (code) VALUES ('chat.read'),('chat.join'),('chat.send'),('chat.moderate'),('chat.manage');
