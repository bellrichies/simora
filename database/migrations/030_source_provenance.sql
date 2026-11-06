CREATE TABLE data_sources (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 access_status ENUM('pending','approved','suspended','revoked') NOT NULL DEFAULT 'pending',
 UNIQUE KEY uq_source_code (code)
) ENGINE=InnoDB;
CREATE TABLE source_access_grants (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 source_id BIGINT UNSIGNED NOT NULL,
 scope_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 access_method VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 permission_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 evidence_reference VARCHAR(255) NOT NULL,
 valid_from DATETIME(6) NOT NULL,
 expires_at DATETIME(6) NOT NULL,
 revoked_at DATETIME(6) NULL,
 retention_until DATETIME(6) NULL,
 KEY ix_grant_lookup (source_id,scope_key,permission_code,expires_at),
 CONSTRAINT fk_grant_source FOREIGN KEY (source_id) REFERENCES data_sources(id),
 CONSTRAINT chk_grant_interval CHECK (expires_at > valid_from)
) ENGINE=InnoDB;
CREATE TABLE source_league_mappings (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 source_id BIGINT UNSIGNED NOT NULL,
 scope_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 external_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 league_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY uq_source_league_external (source_id,scope_key,external_id),
 CONSTRAINT fk_source_league_source FOREIGN KEY (source_id) REFERENCES data_sources(id),
 CONSTRAINT fk_source_league_league FOREIGN KEY (league_id) REFERENCES virtual_leagues(id)
) ENGINE=InnoDB;
CREATE TABLE source_fixture_mappings (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 source_id BIGINT UNSIGNED NOT NULL,
 scope_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 external_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 match_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY uq_source_fixture_external (source_id,scope_key,external_id),
 CONSTRAINT fk_source_fixture_source FOREIGN KEY (source_id) REFERENCES data_sources(id),
 CONSTRAINT fk_source_fixture_match FOREIGN KEY (match_id) REFERENCES football_matches(id)
) ENGINE=InnoDB;
CREATE TABLE ingestion_batches (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 source_id BIGINT UNSIGNED NOT NULL,
 idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 partition_key ENUM('synthetic','authorized_live','approved_backfill') NOT NULL,
 committed_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_batch_key (source_id,idempotency_key),
 CONSTRAINT fk_batch_source FOREIGN KEY (source_id) REFERENCES data_sources(id)
) ENGINE=InnoDB;
CREATE TABLE raw_source_payloads (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 source_id BIGINT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 storage_uri VARCHAR(1024) NOT NULL,
 sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 parser_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_published_at DATETIME(6) NULL,
 observed_at DATETIME(6) NOT NULL,
 available_at DATETIME(6) NOT NULL,
 ingested_at DATETIME(6) NOT NULL,
 CONSTRAINT fk_payload_source FOREIGN KEY (source_id) REFERENCES data_sources(id),
 CONSTRAINT fk_payload_batch FOREIGN KEY (batch_id) REFERENCES ingestion_batches(id),
 CONSTRAINT chk_payload_availability CHECK (available_at >= observed_at)
) ENGINE=InnoDB;
CREATE TABLE ingestion_observations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 raw_payload_id BIGINT UNSIGNED NOT NULL,
 fixture_mapping_id BIGINT UNSIGNED NOT NULL,
 source_record_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 observed_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_observation_record (raw_payload_id,fixture_mapping_id,source_record_key),
 CONSTRAINT fk_observation_payload FOREIGN KEY (raw_payload_id) REFERENCES raw_source_payloads(id),
 CONSTRAINT fk_observation_mapping FOREIGN KEY (fixture_mapping_id) REFERENCES source_fixture_mappings(id)
) ENGINE=InnoDB;
CREATE TABLE collection_jobs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 source_id BIGINT UNSIGNED NOT NULL,
 league_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 job_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY uq_collection_job (source_id,idempotency_key),
 CONSTRAINT fk_job_source FOREIGN KEY (source_id) REFERENCES data_sources(id),
 CONSTRAINT fk_job_league FOREIGN KEY (league_id) REFERENCES virtual_leagues(id)
) ENGINE=InnoDB;
CREATE TABLE collection_cursors (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 source_id BIGINT UNSIGNED NOT NULL,
 league_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 stream_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 checkpoint VARCHAR(255) NOT NULL,
 last_batch_id BIGINT UNSIGNED NOT NULL,
 version BIGINT UNSIGNED NOT NULL,
 UNIQUE KEY uq_cursor_scope (source_id,league_id,stream_key),
 CONSTRAINT fk_cursor_source FOREIGN KEY (source_id) REFERENCES data_sources(id),
 CONSTRAINT fk_cursor_league FOREIGN KEY (league_id) REFERENCES virtual_leagues(id),
 CONSTRAINT fk_cursor_batch FOREIGN KEY (last_batch_id) REFERENCES ingestion_batches(id)
) ENGINE=InnoDB;
CREATE TABLE transactional_outbox (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 event_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload JSON NOT NULL,
 created_at DATETIME(6) NOT NULL,
 published_at DATETIME(6) NULL,
 UNIQUE KEY uq_outbox_event (event_key),
 CONSTRAINT fk_outbox_batch FOREIGN KEY (batch_id) REFERENCES ingestion_batches(id)
) ENGINE=InnoDB;
CREATE TABLE data_quality_issues (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 source_id BIGINT UNSIGNED NOT NULL,
 league_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
 match_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
 code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 severity VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 CONSTRAINT fk_quality_source FOREIGN KEY (source_id) REFERENCES data_sources(id),
 CONSTRAINT fk_quality_league FOREIGN KEY (league_id) REFERENCES virtual_leagues(id),
 CONSTRAINT fk_quality_match FOREIGN KEY (match_id) REFERENCES football_matches(id)
) ENGINE=InnoDB;
CREATE TABLE league_sync_metrics (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 league_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 sampled_at DATETIME(6) NOT NULL,
 window_seconds INT UNSIGNED NOT NULL,
 completeness DECIMAL(5,4) NULL,
 UNIQUE KEY uq_league_metric_window (league_id,sampled_at,window_seconds),
 CONSTRAINT fk_metrics_league FOREIGN KEY (league_id) REFERENCES virtual_leagues(id)
) ENGINE=InnoDB;
