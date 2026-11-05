-- Reference schema for a future MySQL 8 backend; not applied by the demo.
-- Store DATETIME values in UTC. Import IDs exactly as provided by the read-model contract.
CREATE TABLE platforms (
    id VARCHAR(32) PRIMARY KEY,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE leagues (
    id VARCHAR(64) PRIMARY KEY,
    platform_id VARCHAR(32) NOT NULL,
    name VARCHAR(100) NOT NULL,
    FOREIGN KEY (platform_id) REFERENCES platforms(id)
) ENGINE=InnoDB;

CREATE TABLE teams (
    id VARCHAR(32) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(8) NOT NULL,
    kit_id VARCHAR(32) NOT NULL,
    shirt_asset VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE prediction_models (
    id VARCHAR(64) PRIMARY KEY,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE markets (
    id VARCHAR(32) PRIMARY KEY,
    label VARCHAR(64) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE league_weeks (
    id VARCHAR(64) PRIMARY KEY,
    league_id VARCHAR(64) NOT NULL,
    week_number SMALLINT UNSIGNED NOT NULL,
    season VARCHAR(16) NOT NULL,
    week_state VARCHAR(16) NOT NULL,
    UNIQUE KEY league_season_week (league_id, season, week_number),
    FOREIGN KEY (league_id) REFERENCES leagues(id)
) ENGINE=InnoDB;

CREATE TABLE football_matches (
    id VARCHAR(96) PRIMARY KEY,
    week_id VARCHAR(64) NOT NULL,
    home_team_id VARCHAR(32) NOT NULL,
    away_team_id VARCHAR(32) NOT NULL,
    kickoff_at DATETIME NOT NULL,
    match_state VARCHAR(16) NOT NULL,
    home_score SMALLINT UNSIGNED NULL,
    away_score SMALLINT UNSIGNED NULL,
    KEY schedule_lookup (week_id, match_state, kickoff_at, id),
    KEY history_order (kickoff_at, id),
    FOREIGN KEY (week_id) REFERENCES league_weeks(id),
    FOREIGN KEY (home_team_id) REFERENCES teams(id),
    FOREIGN KEY (away_team_id) REFERENCES teams(id),
    CHECK (home_team_id <> away_team_id),
    CHECK ((match_state = 'settled' AND home_score IS NOT NULL AND away_score IS NOT NULL)
        OR (match_state <> 'settled' AND home_score IS NULL AND away_score IS NULL))
) ENGINE=InnoDB;

CREATE TABLE predictions (
    id VARCHAR(128) PRIMARY KEY,
    match_id VARCHAR(96) NOT NULL,
    market_id VARCHAR(32) NOT NULL,
    model_id VARCHAR(64) NOT NULL,
    scope VARCHAR(16) NOT NULL,
    odds_hundredths INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    is_demo BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE KEY match_model_market (match_id, model_id, market_id),
    KEY model_market_history (model_id, market_id, scope, match_id),
    KEY market_history (market_id, scope, match_id, id),
    FOREIGN KEY (match_id) REFERENCES football_matches(id),
    FOREIGN KEY (market_id) REFERENCES markets(id),
    FOREIGN KEY (model_id) REFERENCES prediction_models(id),
    CHECK (odds_hundredths > 100)
) ENGINE=InnoDB;
