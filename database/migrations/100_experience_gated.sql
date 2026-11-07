CREATE TABLE referral_codes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 code CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 policy_reference VARCHAR(255) NOT NULL,
 revoked_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_referral_code (code),
 UNIQUE KEY uq_referral_code_owner (user_id),
 UNIQUE KEY uq_referral_code_scope (user_id,id),
 CONSTRAINT fk_referral_code_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE referral_attributions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 referrer_user_id BIGINT UNSIGNED NOT NULL,
 referred_user_id BIGINT UNSIGNED NOT NULL,
 code_id BIGINT UNSIGNED NOT NULL,
 policy_reference VARCHAR(255) NOT NULL,
 consent_reference VARCHAR(255) NOT NULL,
 registration_reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status ENUM('registered','disqualified') NOT NULL DEFAULT 'registered',
 created_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_attribution_referred (referred_user_id),
 UNIQUE KEY uq_attribution_registration (registration_reference),
 UNIQUE KEY uq_attribution_owner (referrer_user_id,id),
 CONSTRAINT fk_attribution_code_owner FOREIGN KEY (referrer_user_id,code_id) REFERENCES referral_codes(user_id,id),
 CONSTRAINT fk_attribution_referred FOREIGN KEY (referred_user_id) REFERENCES users(id),
 CONSTRAINT chk_attribution_not_self CHECK (referrer_user_id<>referred_user_id)
) ENGINE=InnoDB;
CREATE TABLE referral_rewards (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 attribution_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 policy_reference VARCHAR(255) NOT NULL,
 event_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status ENUM('blocked','rejected') NOT NULL DEFAULT 'blocked',
 created_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_referral_reward_event (event_key),
 CONSTRAINT fk_referral_reward_owner FOREIGN KEY (user_id,attribution_id) REFERENCES referral_attributions(referrer_user_id,id)
) ENGINE=InnoDB;
CREATE TABLE reviews (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 author_user_id BIGINT UNSIGNED NOT NULL,
 rating TINYINT UNSIGNED NOT NULL,
 title VARCHAR(120) NOT NULL,
 body TEXT NOT NULL,
 status ENUM('pending','published','rejected','hidden','withdrawn') NOT NULL DEFAULT 'pending',
 policy_reference VARCHAR(255) NOT NULL,
 request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL,
 published_at DATETIME(6) NULL,
 UNIQUE KEY uq_review_public (public_id),
 UNIQUE KEY uq_review_author (author_user_id),
 KEY ix_reviews_published (status,published_at,id),
 CONSTRAINT fk_review_author FOREIGN KEY (author_user_id) REFERENCES users(id),
 CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;
CREATE TABLE review_moderation (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 review_id BIGINT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 from_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 to_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reason_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 policy_reference VARCHAR(255) NOT NULL,
 request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 resulting_version BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_review_moderation_request (actor_user_id,request_key),
 CONSTRAINT fk_review_moderation_review FOREIGN KEY (review_id) REFERENCES reviews(id),
 CONSTRAINT fk_review_moderation_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE support_tickets (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 owner_user_id BIGINT UNSIGNED NOT NULL,
 assigned_user_id BIGINT UNSIGNED NULL,
 subject VARCHAR(160) NOT NULL,
 category ENUM('general','account','billing','technical','privacy_security') NOT NULL,
 status ENUM('open','waiting_support','waiting_customer','resolved','closed') NOT NULL DEFAULT 'open',
 priority ENUM('normal') NOT NULL DEFAULT 'normal',
 policy_reference VARCHAR(255) NOT NULL,
 request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL,
 updated_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_support_ticket_public (public_id),
 UNIQUE KEY uq_support_ticket_request (owner_user_id,request_key),
 KEY ix_support_ticket_owner (owner_user_id,id),
 KEY ix_support_ticket_assigned (assigned_user_id,id),
 CONSTRAINT fk_support_ticket_owner FOREIGN KEY (owner_user_id) REFERENCES users(id),
 CONSTRAINT fk_support_ticket_agent FOREIGN KEY (assigned_user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE support_messages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 ticket_id BIGINT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 body TEXT NOT NULL,
 visibility ENUM('customer','internal') NOT NULL,
 action ENUM('reply','note','resolve') NOT NULL,
 request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 resulting_version BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_support_message_public (public_id),
 UNIQUE KEY uq_support_message_request (actor_user_id,request_key),
 KEY ix_support_message_ticket (ticket_id,id),
 CONSTRAINT fk_support_message_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id),
 CONSTRAINT fk_support_message_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE faq_articles (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 slug VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version BIGINT UNSIGNED NOT NULL,
 title VARCHAR(160) NOT NULL,
 body TEXT NOT NULL,
 status ENUM('draft','published','withdrawn') NOT NULL DEFAULT 'draft',
 author_user_id BIGINT UNSIGNED NOT NULL,
 publisher_user_id BIGINT UNSIGNED NULL,
 policy_reference VARCHAR(255) NOT NULL,
 request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME(6) NOT NULL,
 published_at DATETIME(6) NULL,
 UNIQUE KEY uq_faq_slug (slug),
 UNIQUE KEY uq_faq_request (author_user_id,request_key),
 CONSTRAINT fk_faq_author FOREIGN KEY (author_user_id) REFERENCES users(id),
 CONSTRAINT fk_faq_publisher FOREIGN KEY (publisher_user_id) REFERENCES users(id),
 CONSTRAINT chk_faq_publication CHECK (status<>'published' OR (publisher_user_id IS NOT NULL AND published_at IS NOT NULL AND publisher_user_id<>author_user_id))
) ENGINE=InnoDB;
ALTER TABLE audit_logs ADD KEY ix_audit_actor_operation_time (actor_user_id,operation,occurred_at);
ALTER TABLE transactional_outbox
 ADD COLUMN review_id BIGINT UNSIGNED NULL,
 ADD COLUMN support_message_id BIGINT UNSIGNED NULL,
 DROP CHECK chk_outbox_owner,
 ADD CONSTRAINT fk_outbox_review FOREIGN KEY (review_id) REFERENCES reviews(id),
 ADD CONSTRAINT fk_outbox_support_message FOREIGN KEY (support_message_id) REFERENCES support_messages(id),
 ADD CONSTRAINT chk_outbox_owner CHECK ((batch_id IS NOT NULL)+(payment_id IS NOT NULL)+(payment_webhook_id IS NOT NULL)+(review_id IS NOT NULL)+(support_message_id IS NOT NULL)=1);
INSERT INTO permissions (code) VALUES
 ('referrals.read'),('referrals.create'),('reviews.read'),('reviews.submit'),('reviews.moderate'),
 ('support.read'),('support.create'),('support.reply'),('support.triage'),('support.manage'),('support.agent'),('support.restricted'),
 ('support.articles.write'),('support.articles.publish');
