CREATE TABLE schema_migrations (
    id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    applied_at DATETIME(6) NULL,
    applied_by VARCHAR(128) NOT NULL,
    duration_ms BIGINT UNSIGNED NULL,
    dependencies JSON NOT NULL,
    status ENUM('running','succeeded','failed') NOT NULL,
    build_sha VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
) ENGINE=InnoDB
