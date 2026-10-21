-- CR7.com.tr schema for SQLite 3.24+ (local development and tests).
-- Mirrors schema.mysql.sql: same tables, columns, defaults and unique constraints.
-- Idempotent: CREATE TABLE / CREATE INDEX IF NOT EXISTS. DATETIME values are UTC text.

CREATE TABLE IF NOT EXISTS settings (
  setting_key TEXT NOT NULL PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  created_at TEXT NOT NULL,
  last_login_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS login_attempts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ip TEXT NOT NULL,
  username TEXT NULL,
  attempted_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_time ON login_attempts (ip, attempted_at);

CREATE TABLE IF NOT EXISTS api_cache (
  endpoint TEXT NOT NULL PRIMARY KEY,
  payload TEXT NOT NULL,
  status INTEGER NOT NULL DEFAULT 200,
  fetched_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS cron_runs (
  task TEXT NOT NULL PRIMARY KEY,
  last_started_at TEXT NULL,
  last_finished_at TEXT NULL,
  last_status TEXT NULL,
  last_message TEXT NULL
);

CREATE TABLE IF NOT EXISTS player (
  id INTEGER NOT NULL PRIMARY KEY,
  name TEXT NULL,
  full_name TEXT NULL,
  birth_date TEXT NULL,
  birth_place TEXT NULL,
  height_cm INTEGER NULL,
  foot TEXT NULL,
  position TEXT NULL,
  side_positions TEXT NULL,
  shirt_number INTEGER NULL,
  current_club_id TEXT NULL,
  contract_until TEXT NULL,
  agent TEXT NULL,
  outfitter TEXT NULL,
  market_value INTEGER NULL,
  market_value_date TEXT NULL,
  highest_market_value INTEGER NULL,
  highest_market_value_date TEXT NULL,
  portrait_url TEXT NULL,
  payload TEXT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS clubs (
  id TEXT NOT NULL PRIMARY KEY,
  name TEXT NOT NULL,
  short_name TEXT NULL,
  slug TEXT NOT NULL,
  country_id INTEGER NULL,
  is_national_team INTEGER NOT NULL DEFAULT 0,
  logo_url TEXT NULL,
  color1 TEXT NULL,
  color2 TEXT NULL,
  updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_clubs_slug ON clubs (slug);

CREATE TABLE IF NOT EXISTS competitions (
  id TEXT NOT NULL PRIMARY KEY,
  name TEXT NOT NULL,
  short_name TEXT NULL,
  logo_url TEXT NULL,
  type_id INTEGER NULL,
  country_id INTEGER NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS stats_season (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  season_id INTEGER NOT NULL,
  season_label TEXT NOT NULL,
  competition_id TEXT NOT NULL,
  club_id TEXT NOT NULL,
  appearances INTEGER NOT NULL DEFAULT 0,
  goals INTEGER NOT NULL DEFAULT 0,
  assists INTEGER NOT NULL DEFAULT 0,
  own_goals INTEGER NOT NULL DEFAULT 0,
  minutes INTEGER NOT NULL DEFAULT 0,
  starts INTEGER NOT NULL DEFAULT 0,
  sub_in INTEGER NOT NULL DEFAULT 0,
  sub_out INTEGER NOT NULL DEFAULT 0,
  yellow INTEGER NOT NULL DEFAULT 0,
  yellow_red INTEGER NOT NULL DEFAULT 0,
  red INTEGER NOT NULL DEFAULT 0,
  penalty_goals INTEGER NOT NULL DEFAULT 0,
  penalty_attempts INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NULL,
  UNIQUE (season_id, competition_id, club_id)
);

CREATE TABLE IF NOT EXISTS stats_club (
  club_id TEXT NOT NULL PRIMARY KEY,
  appearances INTEGER NOT NULL DEFAULT 0,
  goals INTEGER NOT NULL DEFAULT 0,
  assists INTEGER NOT NULL DEFAULT 0,
  own_goals INTEGER NOT NULL DEFAULT 0,
  minutes INTEGER NOT NULL DEFAULT 0,
  starts INTEGER NOT NULL DEFAULT 0,
  sub_in INTEGER NOT NULL DEFAULT 0,
  sub_out INTEGER NOT NULL DEFAULT 0,
  yellow INTEGER NOT NULL DEFAULT 0,
  yellow_red INTEGER NOT NULL DEFAULT 0,
  red INTEGER NOT NULL DEFAULT 0,
  penalty_goals INTEGER NOT NULL DEFAULT 0,
  penalty_attempts INTEGER NOT NULL DEFAULT 0,
  first_season_id INTEGER NULL,
  last_season_id INTEGER NULL,
  updated_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS stats_competition (
  competition_id TEXT NOT NULL PRIMARY KEY,
  appearances INTEGER NOT NULL DEFAULT 0,
  goals INTEGER NOT NULL DEFAULT 0,
  assists INTEGER NOT NULL DEFAULT 0,
  own_goals INTEGER NOT NULL DEFAULT 0,
  minutes INTEGER NOT NULL DEFAULT 0,
  starts INTEGER NOT NULL DEFAULT 0,
  sub_in INTEGER NOT NULL DEFAULT 0,
  sub_out INTEGER NOT NULL DEFAULT 0,
  yellow INTEGER NOT NULL DEFAULT 0,
  yellow_red INTEGER NOT NULL DEFAULT 0,
  red INTEGER NOT NULL DEFAULT 0,
  penalty_goals INTEGER NOT NULL DEFAULT 0,
  penalty_attempts INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS stats_summary (
  scope TEXT NOT NULL PRIMARY KEY,
  appearances INTEGER NOT NULL DEFAULT 0,
  goals INTEGER NOT NULL DEFAULT 0,
  assists INTEGER NOT NULL DEFAULT 0,
  own_goals INTEGER NOT NULL DEFAULT 0,
  minutes INTEGER NOT NULL DEFAULT 0,
  starts INTEGER NOT NULL DEFAULT 0,
  sub_in INTEGER NOT NULL DEFAULT 0,
  sub_out INTEGER NOT NULL DEFAULT 0,
  yellow INTEGER NOT NULL DEFAULT 0,
  yellow_red INTEGER NOT NULL DEFAULT 0,
  red INTEGER NOT NULL DEFAULT 0,
  penalty_goals INTEGER NOT NULL DEFAULT 0,
  penalty_attempts INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS games (
  game_id TEXT NOT NULL PRIMARY KEY,
  date_utc TEXT NOT NULL,
  season_id INTEGER NOT NULL,
  season_label TEXT NULL,
  competition_id TEXT NULL,
  club_id TEXT NULL,
  opponent_id TEXT NULL,
  venue TEXT NULL,
  goals_for INTEGER NULL,
  goals_against INTEGER NULL,
  result TEXT NULL,
  participation TEXT NULL,
  is_starting INTEGER NULL DEFAULT 0,
  minutes INTEGER NULL DEFAULT 0,
  goals INTEGER NULL DEFAULT 0,
  assists INTEGER NULL DEFAULT 0,
  penalty_goals INTEGER NULL DEFAULT 0,
  yellow INTEGER NULL DEFAULT 0,
  red INTEGER NULL DEFAULT 0,
  is_captain INTEGER NULL DEFAULT 0,
  shirt_number INTEGER NULL,
  is_national INTEGER NULL DEFAULT 0,
  shots INTEGER NULL,
  shots_on_target INTEGER NULL,
  passes INTEGER NULL,
  pass_accuracy REAL NULL,
  updated_at TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_games_date ON games (date_utc);
CREATE INDEX IF NOT EXISTS idx_games_season ON games (season_id);
CREATE INDEX IF NOT EXISTS idx_games_competition ON games (competition_id);
CREATE INDEX IF NOT EXISTS idx_games_opponent ON games (opponent_id);

CREATE TABLE IF NOT EXISTS transfers (
  id TEXT NOT NULL PRIMARY KEY,
  transfer_date TEXT NULL,
  season_label TEXT NULL,
  from_club_id TEXT NULL,
  to_club_id TEXT NULL,
  fee_value INTEGER NULL,
  fee_label TEXT NULL,
  market_value INTEGER NULL,
  age INTEGER NULL,
  transfer_type TEXT NULL,
  contract_until TEXT NULL,
  updated_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS market_values (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  value_date TEXT NOT NULL UNIQUE,
  value INTEGER NOT NULL,
  club_id TEXT NULL,
  age INTEGER NULL,
  season_id INTEGER NULL
);

CREATE TABLE IF NOT EXISTS national_career (
  club_id TEXT NOT NULL PRIMARY KEY,
  games INTEGER NULL DEFAULT 0,
  goals INTEGER NULL DEFAULT 0,
  debut_date TEXT NULL,
  last_game_date TEXT NULL,
  shirt_number INTEGER NULL,
  career_state TEXT NULL,
  updated_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS injuries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  injury_key TEXT NOT NULL UNIQUE,
  season_id INTEGER NULL,
  name TEXT NOT NULL,
  start_date TEXT NULL,
  end_date TEXT NULL,
  days INTEGER NULL,
  missed_games INTEGER NULL
);

CREATE TABLE IF NOT EXISTS news_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  source TEXT NOT NULL,
  source_name TEXT NULL,
  url TEXT NOT NULL,
  url_hash TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  title_norm TEXT NOT NULL,
  description TEXT NULL,
  image_url TEXT NULL,
  published_at TEXT NULL,
  fetched_at TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  post_id INTEGER NULL,
  attempts INTEGER NOT NULL DEFAULT 0,
  note TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_news_items_status ON news_items (status, published_at);

CREATE TABLE IF NOT EXISTS posts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  news_item_id INTEGER NULL,
  slug TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  meta_title TEXT NULL,
  summary TEXT NOT NULL,
  body TEXT NOT NULL,
  tags TEXT NULL,
  category TEXT NOT NULL DEFAULT 'haber',
  source_name TEXT NULL,
  source_url TEXT NULL,
  image_path TEXT NULL,
  is_spun INTEGER NOT NULL DEFAULT 0,
  uniqueness REAL NULL,
  source_count INTEGER NOT NULL DEFAULT 1,
  is_indexable INTEGER NOT NULL DEFAULT 1,
  status TEXT NOT NULL DEFAULT 'published',
  views INTEGER NOT NULL DEFAULT 0,
  published_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_posts_status ON posts (status, published_at);

CREATE TABLE IF NOT EXISTS videos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  youtube_id TEXT NOT NULL UNIQUE,
  slug TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  description TEXT NULL,
  channel_id TEXT NULL,
  channel_title TEXT NULL,
  category TEXT NOT NULL DEFAULT 'genel',
  thumbnail_url TEXT NULL,
  duration_seconds INTEGER NULL,
  view_count INTEGER NULL,
  published_at TEXT NULL,
  source TEXT NOT NULL DEFAULT 'manual',
  is_featured INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'published',
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_videos_status ON videos (status, published_at);

CREATE TABLE IF NOT EXISTS products (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  club_id TEXT NULL,
  club_name TEXT NOT NULL,
  season TEXT NULL,
  kit_type TEXT NOT NULL DEFAULT 'ic-saha',
  title TEXT NOT NULL,
  description TEXT NULL,
  price REAL NOT NULL DEFAULT 0,
  old_price REAL NULL,
  sizes TEXT NOT NULL DEFAULT 'S,M,L,XL,XXL',
  kids_sizes TEXT NULL,
  allow_print INTEGER NOT NULL DEFAULT 1,
  print_price REAL NOT NULL DEFAULT 0,
  image_path TEXT NULL,
  color1 TEXT NULL,
  color2 TEXT NULL,
  stock INTEGER NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_products_active ON products (is_active, sort_order);

CREATE TABLE IF NOT EXISTS orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_no TEXT NOT NULL UNIQUE,
  access_key TEXT NOT NULL UNIQUE,
  product_id INTEGER NOT NULL,
  product_title TEXT NOT NULL,
  size TEXT NOT NULL,
  quantity INTEGER NOT NULL DEFAULT 1,
  print_name TEXT NULL,
  print_number TEXT NULL,
  unit_price REAL NOT NULL,
  shipping_fee REAL NOT NULL DEFAULT 0,
  total REAL NOT NULL,
  customer_name TEXT NOT NULL,
  phone TEXT NOT NULL,
  email TEXT NOT NULL,
  city TEXT NOT NULL,
  district TEXT NULL,
  address TEXT NOT NULL,
  note TEXT NULL,
  payment_method TEXT NOT NULL,
  payment_status TEXT NOT NULL DEFAULT 'bekliyor',
  payment_ref TEXT NULL,
  payment_payload TEXT NULL,
  paid_at TEXT NULL,
  status TEXT NOT NULL DEFAULT 'yeni',
  cargo_tracking TEXT NULL,
  ip TEXT NULL,
  kvkk_consent INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders (status, created_at);
CREATE INDEX IF NOT EXISTS idx_orders_payment_status ON orders (payment_status);

-- Old slugs of renamed posts/products: the public page answers 301 to the current URL.
CREATE TABLE IF NOT EXISTS slug_redirects (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entity TEXT NOT NULL,
  old_slug TEXT NOT NULL,
  target_id INTEGER NOT NULL,
  created_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_slug_redirects ON slug_redirects (entity, old_slug);
