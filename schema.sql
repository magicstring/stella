-- SailePost: database schema (MySQL 5.7+ / MariaDB 10.2+)
-- Fresh install: import this, then seed.sql.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS platforms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(30) NOT NULL UNIQUE,
  caption_max INT,
  hashtag_max INT,
  video_max_sec INT,
  aspect_ratio VARCHAR(10),
  max_file_mb INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS banned_words (
  id INT AUTO_INCREMENT PRIMARY KEY,
  word VARCHAR(100) NOT NULL,
  severity ENUM('warn','fail') DEFAULT 'warn',
  reason VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS trends (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('sound','format','hashtag','product','angle') NOT NULL,
  name VARCHAR(150) NOT NULL,
  platform VARCHAR(30),
  velocity INT DEFAULT 0,
  note VARCHAR(255),
  seen_at DATE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS business_profiles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  description TEXT,
  audience VARCHAR(255),
  location VARCHAR(150),
  tone VARCHAR(100),
  platforms VARCHAR(255),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS checks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  platform_id INT,
  business_id INT NULL,
  caption TEXT,
  file_path VARCHAR(255),
  rule_results LONGTEXT,
  ai_results LONGTEXT,
  fixes LONGTEXT,
  score INT,
  verdict VARCHAR(10),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (platform_id) REFERENCES platforms(id),
  FOREIGN KEY (business_id) REFERENCES business_profiles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS scheduled_posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  check_id INT NOT NULL,
  platform_id INT NOT NULL,
  caption TEXT,
  file_path VARCHAR(255),
  status ENUM('scheduled','posting','posted','simulated','failed') DEFAULT 'scheduled',
  scheduled_at DATETIME NOT NULL,
  posted_at DATETIME NULL,
  external_id VARCHAR(100),
  error VARCHAR(500),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (status, scheduled_at),
  FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE,
  FOREIGN KEY (platform_id) REFERENCES platforms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
