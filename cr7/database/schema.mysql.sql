-- CR7.com.tr schema for MySQL 5.7+ / MariaDB 10.3+ (utf8mb4).
-- Idempotent: every table is created with IF NOT EXISTS, indexes are declared inline.
-- All DATETIME values are stored in UTC ('Y-m-d H:i:s').

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) NOT NULL,
  setting_value TEXT NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(50) NULL,
  attempted_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_login_attempts_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_cache (
  endpoint VARCHAR(191) NOT NULL,
  payload LONGTEXT NOT NULL,
  status INT NOT NULL DEFAULT 200,
  fetched_at DATETIME NOT NULL,
  PRIMARY KEY (endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_runs (
  task VARCHAR(50) NOT NULL,
  last_started_at DATETIME NULL,
  last_finished_at DATETIME NULL,
  last_status VARCHAR(20) NULL,
  last_message TEXT NULL,
  PRIMARY KEY (task)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player (
  id INT NOT NULL,
  name VARCHAR(120) NULL,
  full_name VARCHAR(190) NULL,
  birth_date DATE NULL,
  birth_place VARCHAR(120) NULL,
  height_cm INT NULL,
  foot VARCHAR(30) NULL,
  position VARCHAR(80) NULL,
  side_positions VARCHAR(190) NULL,
  shirt_number INT NULL,
  current_club_id VARCHAR(20) NULL,
  contract_until DATE NULL,
  agent VARCHAR(120) NULL,
  outfitter VARCHAR(80) NULL,
  market_value BIGINT NULL,
  market_value_date DATE NULL,
  highest_market_value BIGINT NULL,
  highest_market_value_date DATE NULL,
  portrait_url VARCHAR(500) NULL,
  payload LONGTEXT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clubs (
  id VARCHAR(20) NOT NULL,
  name VARCHAR(190) NOT NULL,
  short_name VARCHAR(120) NULL,
  slug VARCHAR(150) NOT NULL,
  country_id INT NULL,
  is_national_team TINYINT NOT NULL DEFAULT 0,
  logo_url VARCHAR(500) NULL,
  color1 VARCHAR(10) NULL,
  color2 VARCHAR(10) NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_clubs_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS competitions (
  id VARCHAR(20) NOT NULL,
  name VARCHAR(190) NOT NULL,
  short_name VARCHAR(120) NULL,
  logo_url VARCHAR(500) NULL,
  type_id INT NULL,
  country_id INT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stats_season (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  season_id INT NOT NULL,
  season_label VARCHAR(10) NOT NULL,
  competition_id VARCHAR(20) NOT NULL,
  club_id VARCHAR(20) NOT NULL,
  appearances INT NOT NULL DEFAULT 0,
  goals INT NOT NULL DEFAULT 0,
  assists INT NOT NULL DEFAULT 0,
  own_goals INT NOT NULL DEFAULT 0,
  minutes INT NOT NULL DEFAULT 0,
  starts INT NOT NULL DEFAULT 0,
  sub_in INT NOT NULL DEFAULT 0,
  sub_out INT NOT NULL DEFAULT 0,
  yellow INT NOT NULL DEFAULT 0,
  yellow_red INT NOT NULL DEFAULT 0,
  red INT NOT NULL DEFAULT 0,
  penalty_goals INT NOT NULL DEFAULT 0,
  penalty_attempts INT NOT NULL DEFAULT 0,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stats_season (season_id, competition_id, club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stats_club (
  club_id VARCHAR(20) NOT NULL,
  appearances INT NOT NULL DEFAULT 0,
  goals INT NOT NULL DEFAULT 0,
  assists INT NOT NULL DEFAULT 0,
  own_goals INT NOT NULL DEFAULT 0,
  minutes INT NOT NULL DEFAULT 0,
  starts INT NOT NULL DEFAULT 0,
  sub_in INT NOT NULL DEFAULT 0,
  sub_out INT NOT NULL DEFAULT 0,
  yellow INT NOT NULL DEFAULT 0,
  yellow_red INT NOT NULL DEFAULT 0,
  red INT NOT NULL DEFAULT 0,
  penalty_goals INT NOT NULL DEFAULT 0,
  penalty_attempts INT NOT NULL DEFAULT 0,
  first_season_id INT NULL,
  last_season_id INT NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stats_competition (
  competition_id VARCHAR(20) NOT NULL,
  appearances INT NOT NULL DEFAULT 0,
  goals INT NOT NULL DEFAULT 0,
  assists INT NOT NULL DEFAULT 0,
  own_goals INT NOT NULL DEFAULT 0,
  minutes INT NOT NULL DEFAULT 0,
  starts INT NOT NULL DEFAULT 0,
  sub_in INT NOT NULL DEFAULT 0,
  sub_out INT NOT NULL DEFAULT 0,
  yellow INT NOT NULL DEFAULT 0,
  yellow_red INT NOT NULL DEFAULT 0,
  red INT NOT NULL DEFAULT 0,
  penalty_goals INT NOT NULL DEFAULT 0,
  penalty_attempts INT NOT NULL DEFAULT 0,
  updated_at DATETIME NULL,
  PRIMARY KEY (competition_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stats_summary (
  scope VARCHAR(30) NOT NULL,
  appearances INT NOT NULL DEFAULT 0,
  goals INT NOT NULL DEFAULT 0,
  assists INT NOT NULL DEFAULT 0,
  own_goals INT NOT NULL DEFAULT 0,
  minutes INT NOT NULL DEFAULT 0,
  starts INT NOT NULL DEFAULT 0,
  sub_in INT NOT NULL DEFAULT 0,
  sub_out INT NOT NULL DEFAULT 0,
  yellow INT NOT NULL DEFAULT 0,
  yellow_red INT NOT NULL DEFAULT 0,
  red INT NOT NULL DEFAULT 0,
  penalty_goals INT NOT NULL DEFAULT 0,
  penalty_attempts INT NOT NULL DEFAULT 0,
  updated_at DATETIME NULL,
  PRIMARY KEY (scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS games (
  game_id VARCHAR(20) NOT NULL,
  date_utc DATETIME NOT NULL,
  season_id INT NOT NULL,
  season_label VARCHAR(10) NULL,
  competition_id VARCHAR(20) NULL,
  club_id VARCHAR(20) NULL,
  opponent_id VARCHAR(20) NULL,
  venue VARCHAR(10) NULL,
  goals_for INT NULL,
  goals_against INT NULL,
  result CHAR(1) NULL,
  participation VARCHAR(40) NULL,
  is_starting TINYINT NULL DEFAULT 0,
  minutes INT NULL DEFAULT 0,
  goals INT NULL DEFAULT 0,
  assists INT NULL DEFAULT 0,
  penalty_goals INT NULL DEFAULT 0,
  yellow INT NULL DEFAULT 0,
  red INT NULL DEFAULT 0,
  is_captain TINYINT NULL DEFAULT 0,
  shirt_number INT NULL,
  is_national TINYINT NULL DEFAULT 0,
  shots INT NULL,
  shots_on_target INT NULL,
  passes INT NULL,
  pass_accuracy DECIMAL(5,1) NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (game_id),
  KEY idx_games_date (date_utc),
  KEY idx_games_season (season_id),
  KEY idx_games_competition (competition_id),
  KEY idx_games_opponent (opponent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transfers (
  id VARCHAR(20) NOT NULL,
  transfer_date DATE NULL,
  season_label VARCHAR(10) NULL,
  from_club_id VARCHAR(20) NULL,
  to_club_id VARCHAR(20) NULL,
  fee_value BIGINT NULL,
  fee_label VARCHAR(60) NULL,
  market_value BIGINT NULL,
  age INT NULL,
  transfer_type VARCHAR(30) NULL,
  contract_until DATE NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS market_values (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  value_date DATE NOT NULL,
  value BIGINT NOT NULL,
  club_id VARCHAR(20) NULL,
  age INT NULL,
  season_id INT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_market_values_date (value_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS national_career (
  club_id VARCHAR(20) NOT NULL,
  games INT NULL DEFAULT 0,
  goals INT NULL DEFAULT 0,
  debut_date DATE NULL,
  last_game_date DATE NULL,
  shirt_number INT NULL,
  career_state VARCHAR(40) NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS injuries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  injury_key VARCHAR(64) NOT NULL,
  season_id INT NULL,
  name VARCHAR(190) NOT NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  days INT NULL,
  missed_games INT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_injuries_key (injury_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  source VARCHAR(60) NOT NULL,
  source_name VARCHAR(150) NULL,
  url VARCHAR(1000) NOT NULL,
  url_hash CHAR(40) NOT NULL,
  title VARCHAR(500) NOT NULL,
  title_norm VARCHAR(500) NOT NULL,
  description TEXT NULL,
  image_url VARCHAR(1000) NULL,
  published_at DATETIME NULL,
  fetched_at DATETIME NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  post_id INT NULL,
  attempts INT NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_news_items_url_hash (url_hash),
  KEY idx_news_items_status (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  news_item_id INT NULL,
  slug VARCHAR(190) NOT NULL,
  title VARCHAR(255) NOT NULL,
  meta_title VARCHAR(255) NULL,
  summary VARCHAR(500) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  tags VARCHAR(500) NULL,
  category VARCHAR(50) NOT NULL DEFAULT 'haber',
  source_name VARCHAR(150) NULL,
  source_url VARCHAR(1000) NULL,
  image_path VARCHAR(255) NULL,
  is_spun TINYINT NOT NULL DEFAULT 0,
  uniqueness DECIMAL(5,2) NULL,
  source_count INT NOT NULL DEFAULT 1,
  is_indexable TINYINT NOT NULL DEFAULT 1,
  status VARCHAR(20) NOT NULL DEFAULT 'published',
  views INT NOT NULL DEFAULT 0,
  published_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_posts_slug (slug),
  KEY idx_posts_status (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS videos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  youtube_id VARCHAR(20) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  channel_id VARCHAR(40) NULL,
  channel_title VARCHAR(150) NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'genel',
  thumbnail_url VARCHAR(500) NULL,
  duration_seconds INT NULL,
  view_count BIGINT NULL,
  published_at DATETIME NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'manual',
  is_featured TINYINT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'published',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_videos_youtube_id (youtube_id),
  UNIQUE KEY uq_videos_slug (slug),
  KEY idx_videos_status (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(190) NOT NULL,
  club_id VARCHAR(20) NULL,
  club_name VARCHAR(150) NOT NULL,
  season VARCHAR(20) NULL,
  kit_type VARCHAR(20) NOT NULL DEFAULT 'ic-saha',
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  old_price DECIMAL(10,2) NULL,
  sizes VARCHAR(190) NOT NULL DEFAULT 'S,M,L,XL,XXL',
  kids_sizes VARCHAR(190) NULL,
  allow_print TINYINT NOT NULL DEFAULT 1,
  print_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  image_path VARCHAR(255) NULL,
  color1 VARCHAR(10) NULL,
  color2 VARCHAR(10) NULL,
  stock INT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_slug (slug),
  KEY idx_products_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_no VARCHAR(20) NOT NULL,
  access_key CHAR(32) NOT NULL,
  product_id INT NOT NULL,
  product_title VARCHAR(255) NOT NULL,
  size VARCHAR(20) NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  print_name VARCHAR(30) NULL,
  print_number VARCHAR(3) NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  shipping_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  total DECIMAL(10,2) NOT NULL,
  customer_name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  email VARCHAR(190) NOT NULL,
  city VARCHAR(80) NOT NULL,
  district VARCHAR(80) NULL,
  address TEXT NOT NULL,
  note TEXT NULL,
  payment_method VARCHAR(20) NOT NULL,
  payment_status VARCHAR(20) NOT NULL DEFAULT 'bekliyor',
  payment_ref VARCHAR(190) NULL,
  payment_payload TEXT NULL,
  paid_at DATETIME NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'yeni',
  cargo_tracking VARCHAR(100) NULL,
  ip VARCHAR(45) NULL,
  kvkk_consent TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_order_no (order_no),
  UNIQUE KEY uq_orders_access_key (access_key),
  KEY idx_orders_status (status, created_at),
  KEY idx_orders_payment_status (payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Old slugs of renamed posts/products: the public page answers 301 to the current URL.
CREATE TABLE IF NOT EXISTS slug_redirects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity VARCHAR(20) NOT NULL,
  old_slug VARCHAR(190) NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slug_redirects (entity, old_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
