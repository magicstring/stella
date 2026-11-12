-- Only if you already imported the Milestone 1 schema.sql.
-- Adds the columns and table the full app needs. Run once.
ALTER TABLE checks ADD COLUMN fixes LONGTEXT AFTER ai_results;
ALTER TABLE checks ADD COLUMN verdict VARCHAR(10) AFTER score;

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
