CREATE TABLE platforms (
    id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    slug VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(100) NOT NULL,
    availability_state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unavailable',
    UNIQUE KEY uq_platforms_slug (slug)
) ENGINE=InnoDB;
CREATE TABLE virtual_leagues (
    id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    platform_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(100) NOT NULL,
    league_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    UNIQUE KEY uq_leagues_scope (platform_id,id),
    CONSTRAINT fk_virtual_leagues_platform FOREIGN KEY (platform_id) REFERENCES platforms(id)
) ENGINE=InnoDB;
CREATE TABLE league_seasons (
    id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    league_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_season_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    UNIQUE KEY uq_seasons_scope (league_id,id),
    UNIQUE KEY uq_seasons_key (league_id,source_season_key),
    CONSTRAINT fk_seasons_league FOREIGN KEY (league_id) REFERENCES virtual_leagues(id)
) ENGINE=InnoDB;
CREATE TABLE teams (
    id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    league_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_teams_scope (league_id,id),
    CONSTRAINT fk_teams_league FOREIGN KEY (league_id) REFERENCES virtual_leagues(id)
) ENGINE=InnoDB;
CREATE TABLE team_aliases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    league_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    team_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    normalized_alias VARCHAR(191) NOT NULL,
    UNIQUE KEY uq_alias_scope (league_id,normalized_alias),
    CONSTRAINT fk_alias_team_scope FOREIGN KEY (league_id,team_id) REFERENCES teams(league_id,id)
) ENGINE=InnoDB;
CREATE TABLE virtual_rounds (
    id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    league_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    season_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    round_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    UNIQUE KEY uq_rounds_scope (league_id,id),
    UNIQUE KEY uq_rounds_key (season_id,round_key),
    CONSTRAINT fk_rounds_season_scope FOREIGN KEY (league_id,season_id) REFERENCES league_seasons(league_id,id)
) ENGINE=InnoDB;
CREATE TABLE football_matches (
    id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    league_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    round_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    home_team_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    away_team_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kickoff_at DATETIME(6) NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    CONSTRAINT fk_matches_round_scope FOREIGN KEY (league_id,round_id) REFERENCES virtual_rounds(league_id,id),
    CONSTRAINT fk_matches_home_scope FOREIGN KEY (league_id,home_team_id) REFERENCES teams(league_id,id),
    CONSTRAINT fk_matches_away_scope FOREIGN KEY (league_id,away_team_id) REFERENCES teams(league_id,id),
    CONSTRAINT chk_matches_teams CHECK (home_team_id <> away_team_id)
) ENGINE=InnoDB;
CREATE TABLE markets (
    id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    label VARCHAR(64) NOT NULL,
    category VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    side VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    threshold DECIMAL(3,1) NULL
) ENGINE=InnoDB;
CREATE TABLE market_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    market_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version INT UNSIGNED NOT NULL,
    predicate_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    effective_from DATETIME(6) NOT NULL,
    effective_until DATETIME(6) NULL,
    UNIQUE KEY uq_market_rule_version (market_id,version),
    CONSTRAINT fk_rules_market FOREIGN KEY (market_id) REFERENCES markets(id),
    CONSTRAINT chk_rules_interval CHECK (effective_until IS NULL OR effective_until > effective_from)
) ENGINE=InnoDB;
INSERT INTO platforms (id,slug,name,availability_state) VALUES
('sportybet','sportybet','SportyBet','unavailable'),
('betpawa','betpawa','BetPawa','unavailable'),
('betking','betking','BetKing','unavailable');
INSERT INTO markets (id,label,category,side,threshold) VALUES
('over_15','Over 1.5','total_goals','over',1.5),('under_15','Under 1.5','total_goals','under',1.5),
('over_25','Over 2.5','total_goals','over',2.5),('under_25','Under 2.5','total_goals','under',2.5),
('over_35','Over 3.5','total_goals','over',3.5),('under_35','Under 3.5','total_goals','under',3.5),
('btts_yes','BTTS Yes','btts','yes',NULL),('btts_no','BTTS No','btts','no',NULL);
INSERT INTO market_rules (market_id,version,predicate_code,effective_from) SELECT id,1,id,'1970-01-01 00:00:00.000000' FROM markets;
