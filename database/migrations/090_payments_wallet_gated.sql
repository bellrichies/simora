CREATE TABLE invoices (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 invoice_number VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 plan_version_id BIGINT UNSIGNED NOT NULL,
 subscription_id BIGINT UNSIGNED NULL,
 paid_payment_id BIGINT UNSIGNED NULL,
 operation ENUM('purchase','renewal') NOT NULL,
 subtotal_minor BIGINT UNSIGNED NOT NULL,
 tax_minor BIGINT UNSIGNED NOT NULL,
 total_minor BIGINT UNSIGNED NOT NULL,
 currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 validity_seconds INT UNSIGNED NOT NULL,
 status ENUM('unpaid','paid','review') NOT NULL DEFAULT 'unpaid',
 tax_reference VARCHAR(255) NOT NULL,
 invoice_reference VARCHAR(255) NOT NULL,
 approval_reference VARCHAR(255) NOT NULL,
 customer_email_sha256 BINARY(32) NOT NULL,
 created_at DATETIME(6) NOT NULL,
 expires_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_invoice_public (public_id),
 UNIQUE KEY uq_invoice_number (invoice_number),
 UNIQUE KEY uq_invoice_owner (user_id,id),
 CONSTRAINT fk_invoice_user FOREIGN KEY (user_id) REFERENCES users(id),
 CONSTRAINT fk_invoice_plan FOREIGN KEY (plan_version_id) REFERENCES plan_versions(id),
 CONSTRAINT fk_invoice_subscription_owner FOREIGN KEY (user_id,subscription_id) REFERENCES subscriptions(user_id,id),
 CONSTRAINT fk_invoice_subscription_plan FOREIGN KEY (subscription_id,plan_version_id) REFERENCES subscriptions(id,plan_version_id),
 CONSTRAINT chk_invoice_amount CHECK (subtotal_minor>0 AND total_minor=subtotal_minor+tax_minor AND total_minor<=1000000000),
 CONSTRAINT chk_invoice_expiry CHECK (expires_at>created_at)
) ENGINE=InnoDB;
CREATE TABLE payments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 invoice_id BIGINT UNSIGNED NOT NULL,
 provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 account_code VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 environment ENUM('sandbox','live') NOT NULL,
 merchant_reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 provider_reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
 request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 amount_minor BIGINT UNSIGNED NOT NULL,
 currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status ENUM('pending','failed','succeeded','review') NOT NULL DEFAULT 'pending',
 initialized_at DATETIME(6) NULL,
 authorization_url VARCHAR(512) NULL,
 checkout_claimed_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_payment_public (public_id),
 UNIQUE KEY uq_payment_request (user_id,request_key),
 UNIQUE KEY uq_payment_merchant (merchant_reference),
 UNIQUE KEY uq_payment_provider (provider,account_code,environment,provider_reference),
 UNIQUE KEY uq_payment_invoice_owner (user_id,invoice_id,id),
 UNIQUE KEY uq_payment_owner (user_id,id),
 CONSTRAINT fk_payment_invoice_owner FOREIGN KEY (user_id,invoice_id) REFERENCES invoices(user_id,id),
 CONSTRAINT chk_payment_amount CHECK (amount_minor BETWEEN 1 AND 1000000000)
) ENGINE=InnoDB;
ALTER TABLE invoices ADD CONSTRAINT fk_invoice_paid_payment FOREIGN KEY (user_id,id,paid_payment_id) REFERENCES payments(user_id,invoice_id,id);
CREATE TABLE payment_webhooks (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 account_code VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 environment ENUM('sandbox','live') NOT NULL,
 external_event_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merchant_reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
 transaction_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
 payment_id BIGINT UNSIGNED NULL,
 payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 evidence_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 lookup_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 verification_kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status ENUM('received','processed','ignored','quarantined') NOT NULL,
 result_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 received_at DATETIME(6) NOT NULL,
 processed_at DATETIME(6) NULL,
 UNIQUE KEY uq_webhook_event (provider,account_code,environment,external_event_id),
 CONSTRAINT fk_webhook_payment FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB;
CREATE TABLE refunds (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 payment_id BIGINT UNSIGNED NOT NULL,
 webhook_id BIGINT UNSIGNED NOT NULL,
 provider_reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 amount_minor BIGINT UNSIGNED NOT NULL,
 currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status ENUM('observed_review') NOT NULL,
 created_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_refund_observation (webhook_id),
 CONSTRAINT fk_refund_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
 CONSTRAINT fk_refund_webhook FOREIGN KEY (webhook_id) REFERENCES payment_webhooks(id)
) ENGINE=InnoDB;
ALTER TABLE subscription_events MODIFY COLUMN actor_user_id BIGINT UNSIGNED NULL,
 ADD COLUMN payment_id BIGINT UNSIGNED NULL,
 ADD CONSTRAINT fk_subscription_event_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
 ADD CONSTRAINT chk_subscription_event_actor CHECK (actor_user_id IS NOT NULL OR payment_id IS NOT NULL);
ALTER TABLE transactional_outbox MODIFY COLUMN batch_id BIGINT UNSIGNED NULL,
 ADD COLUMN payment_id BIGINT UNSIGNED NULL,
 ADD COLUMN payment_webhook_id BIGINT UNSIGNED NULL,
 ADD CONSTRAINT fk_outbox_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
 ADD CONSTRAINT fk_outbox_payment_webhook FOREIGN KEY (payment_webhook_id) REFERENCES payment_webhooks(id),
 ADD CONSTRAINT chk_outbox_owner CHECK ((batch_id IS NOT NULL)+(payment_id IS NOT NULL)+(payment_webhook_id IS NOT NULL)=1);
CREATE TABLE wallet_accounts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NULL,
 owner_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 account_type ENUM('subscription_credit','subscription_control') NOT NULL,
 UNIQUE KEY uq_wallet_owner (owner_key,currency,account_type),
 CONSTRAINT fk_wallet_account_user FOREIGN KEY (user_id) REFERENCES users(id),
 CONSTRAINT chk_wallet_owner CHECK ((account_type='subscription_credit' AND user_id IS NOT NULL AND owner_key=CONCAT('user:',user_id)) OR
 (account_type='subscription_control' AND user_id IS NULL AND owner_key='system:subscription'))
) ENGINE=InnoDB;
CREATE TABLE wallet_journals (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 external_event_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 purpose ENUM('verified_subscription_funding','subscription_application','approved_reversal') NOT NULL,
 currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payment_id BIGINT UNSIGNED NOT NULL,
 invoice_id BIGINT UNSIGNED NOT NULL,
 reversal_of_id BIGINT UNSIGNED NULL,
 posted_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_wallet_journal_event (external_event_key),
 UNIQUE KEY uq_wallet_reversal (reversal_of_id),
 CONSTRAINT fk_wallet_journal_user FOREIGN KEY (user_id) REFERENCES users(id),
 CONSTRAINT fk_wallet_journal_payment_owner FOREIGN KEY (user_id,invoice_id,payment_id) REFERENCES payments(user_id,invoice_id,id),
 CONSTRAINT fk_wallet_journal_invoice_owner FOREIGN KEY (user_id,invoice_id) REFERENCES invoices(user_id,id),
 CONSTRAINT fk_wallet_journal_reversal FOREIGN KEY (reversal_of_id) REFERENCES wallet_journals(id)
) ENGINE=InnoDB;
CREATE TABLE wallet_postings (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 journal_id BIGINT UNSIGNED NOT NULL,
 account_id BIGINT UNSIGNED NOT NULL,
 debit_minor BIGINT UNSIGNED NOT NULL,
 credit_minor BIGINT UNSIGNED NOT NULL,
 UNIQUE KEY uq_wallet_posting_account (journal_id,account_id),
 CONSTRAINT fk_wallet_posting_journal FOREIGN KEY (journal_id) REFERENCES wallet_journals(id),
 CONSTRAINT fk_wallet_posting_account FOREIGN KEY (account_id) REFERENCES wallet_accounts(id),
 CONSTRAINT chk_wallet_posting_side CHECK ((debit_minor>0 AND credit_minor=0) OR (credit_minor>0 AND debit_minor=0))
) ENGINE=InnoDB;
CREATE TABLE wallet_balances (
 account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 current_minor BIGINT NOT NULL DEFAULT 0,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 CONSTRAINT fk_wallet_balance_account FOREIGN KEY (account_id) REFERENCES wallet_accounts(id)
) ENGINE=InnoDB;
CREATE TABLE wallet_adjustments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 journal_id BIGINT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 request_reference VARCHAR(128) NOT NULL,
 approval_reference VARCHAR(255) NOT NULL,
 created_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_wallet_adjustment_journal (journal_id),
 CONSTRAINT fk_wallet_adjustment_journal FOREIGN KEY (journal_id) REFERENCES wallet_journals(id),
 CONSTRAINT fk_wallet_adjustment_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;
INSERT INTO permissions (code) VALUES ('billing.read'),('billing.checkout'),('billing.credit.adjust');
