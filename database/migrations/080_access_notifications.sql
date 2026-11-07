ALTER TABLE sessions ADD COLUMN csrf_token_hash BINARY(32) NULL;
CREATE TABLE plans (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 name VARCHAR(100) NOT NULL,
 enabled BOOLEAN NOT NULL DEFAULT FALSE,
 UNIQUE KEY uq_plan_code (code)
) ENGINE=InnoDB;
CREATE TABLE plan_versions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 plan_id BIGINT UNSIGNED NOT NULL,
 version INT UNSIGNED NOT NULL,
 features JSON NOT NULL,
 approval_reference VARCHAR(255) NOT NULL,
 approved_by BIGINT UNSIGNED NOT NULL,
 approved_at DATETIME(6) NOT NULL,
 effective_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_plan_version (plan_id,version),
 CONSTRAINT fk_plan_version_plan FOREIGN KEY (plan_id) REFERENCES plans(id),
 CONSTRAINT fk_plan_version_approver FOREIGN KEY (approved_by) REFERENCES users(id),
 CONSTRAINT chk_plan_approval CHECK (effective_at >= approved_at)
) ENGINE=InnoDB;
CREATE TABLE plan_prices (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 plan_version_id BIGINT UNSIGNED NOT NULL,
 currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 amount_minor BIGINT UNSIGNED NOT NULL,
 interval_code ENUM('day','two_weeks','month') NOT NULL,
 UNIQUE KEY uq_plan_price (plan_version_id,currency,interval_code),
 CONSTRAINT fk_price_plan_version FOREIGN KEY (plan_version_id) REFERENCES plan_versions(id)
) ENGINE=InnoDB;
CREATE TABLE subscriptions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 plan_version_id BIGINT UNSIGNED NOT NULL,
 status ENUM('pending','active','expired','revoked') NOT NULL DEFAULT 'pending',
 starts_at DATETIME(6) NOT NULL,
 ends_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_subscription_owner (user_id,id),
 CONSTRAINT fk_subscription_user FOREIGN KEY (user_id) REFERENCES users(id),
 CONSTRAINT fk_subscription_version FOREIGN KEY (plan_version_id) REFERENCES plan_versions(id),
 CONSTRAINT chk_subscription_window CHECK (ends_at > starts_at)
) ENGINE=InnoDB;
CREATE TABLE subscription_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 subscription_id BIGINT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 evidence_reference VARCHAR(255) NOT NULL,
 occurred_at DATETIME(6) NOT NULL,
 CONSTRAINT fk_subscription_event FOREIGN KEY (subscription_id) REFERENCES subscriptions(id),
 CONSTRAINT fk_subscription_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE entitlements (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 subscription_id BIGINT UNSIGNED NULL,
 feature_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 scope_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 quota_limit BIGINT UNSIGNED NULL,
 period_seconds INT UNSIGNED NOT NULL,
 period_anchor_at DATETIME(6) NOT NULL,
 starts_at DATETIME(6) NOT NULL,
 ends_at DATETIME(6) NOT NULL,
 approved_by BIGINT UNSIGNED NOT NULL,
 approval_reference VARCHAR(255) NOT NULL,
 approved_at DATETIME(6) NOT NULL,
 revoked_at DATETIME(6) NULL,
 UNIQUE KEY uq_entitlement_owner (user_id,id),
 KEY ix_entitlement_access (user_id,feature_code,scope_key,starts_at,ends_at),
 CONSTRAINT fk_entitlement_user FOREIGN KEY (user_id) REFERENCES users(id),
 CONSTRAINT fk_entitlement_subscription_owner FOREIGN KEY (user_id,subscription_id) REFERENCES subscriptions(user_id,id),
 CONSTRAINT fk_entitlement_approver FOREIGN KEY (approved_by) REFERENCES users(id),
 CONSTRAINT chk_entitlement_window CHECK (ends_at > starts_at AND starts_at >= approved_at),
 CONSTRAINT chk_entitlement_period CHECK (period_seconds BETWEEN 1 AND 2678400),
 CONSTRAINT chk_entitlement_quota CHECK (quota_limit IS NULL OR quota_limit <= 1000000000)
) ENGINE=InnoDB;
CREATE TABLE usage_counters (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 entitlement_id BIGINT UNSIGNED NOT NULL,
 feature_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 scope_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 period_start DATETIME(6) NOT NULL,
 used_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 UNIQUE KEY uq_usage_window (user_id,feature_code,scope_key,period_start),
 UNIQUE KEY uq_usage_counter_owner (user_id,id),
 CONSTRAINT fk_usage_grant_owner FOREIGN KEY (user_id,entitlement_id) REFERENCES entitlements(user_id,id)
) ENGINE=InnoDB;
CREATE TABLE usage_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 feature_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 counter_id BIGINT UNSIGNED NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 delta INT UNSIGNED NOT NULL,
 status ENUM('consumed') NOT NULL,
 result_json JSON NOT NULL,
 occurred_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_usage_request (user_id,feature_code,request_id),
 CONSTRAINT fk_usage_event_user FOREIGN KEY (user_id) REFERENCES users(id),
 CONSTRAINT fk_usage_event_counter FOREIGN KEY (user_id,counter_id) REFERENCES usage_counters(user_id,id),
 CONSTRAINT chk_usage_delta CHECK (delta BETWEEN 1 AND 1000000)
) ENGINE=InnoDB;
CREATE TABLE notifications (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 event_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload JSON NOT NULL,
 created_at DATETIME(6) NOT NULL,
 read_at DATETIME(6) NULL,
 UNIQUE KEY uq_notification_event (user_id,event_key),
 KEY ix_notification_owner (user_id,id),
 CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE notification_outbox (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 notification_id BIGINT UNSIGNED NOT NULL,
 channel ENUM('in_app') NOT NULL,
 status ENUM('delivered') NOT NULL,
 delivered_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_notification_channel (notification_id,channel),
 CONSTRAINT fk_notification_outbox FOREIGN KEY (notification_id) REFERENCES notifications(id)
) ENGINE=InnoDB;
INSERT INTO plans (code,name) VALUES ('trial','Trial'),('standard','Standard'),('pro','Pro'),('max','Max');
INSERT INTO permissions (code) VALUES ('access.read'),('access.consume'),('access.admin'),('notifications.read'),('notifications.update'),('notifications.publish');
