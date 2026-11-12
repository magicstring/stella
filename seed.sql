-- SailePost: seed data (Milestone 2)
-- Run after schema.sql. Safe to re-run: platforms upsert, banned_words is cleared first.
--
-- PLATFORM LIMITS ARE APPROXIMATE (from memory, not looked up).
-- Verify each one on the platform's official help page before the demo.
SET NAMES utf8mb4;

INSERT INTO platforms (name, caption_max, hashtag_max, video_max_sec, aspect_ratio, max_file_mb) VALUES
  ('TikTok',          2200, 10,  600, '9:16', 100),
  ('Instagram',       2200, 30,  180, '9:16', 100),
  ('Facebook',        5000, 10,  240, '9:16', 100),
  ('YouTube Shorts',   100, 15,  180, '9:16', 100)
ON DUPLICATE KEY UPDATE
  caption_max   = VALUES(caption_max),
  hashtag_max   = VALUES(hashtag_max),
  video_max_sec = VALUES(video_max_sec),
  aspect_ratio  = VALUES(aspect_ratio),
  max_file_mb   = VALUES(max_file_mb);
-- Notes:
-- Facebook allows far longer posts; 5000 is a practical cap for readability.
-- YouTube Shorts caption_max is the title length; the description is longer.
-- hashtag_max for TikTok/Facebook/Shorts is a best-practice cap, not a hard platform limit.
-- max_file_mb is capped to the hosting upload limit, not the platform maximum.

DELETE FROM banned_words;

INSERT INTO banned_words (word, severity, reason) VALUES
  -- Health and medical claims
  ('cure',                    'fail', 'Medical claim; platforms and PH FDA restrict cure claims'),
  ('cures',                   'fail', 'Medical claim; platforms and PH FDA restrict cure claims'),
  ('gamot sa',                'warn', 'Implies a treatment claim (Filipino: "medicine for")'),
  ('miracle',                 'warn', 'Exaggerated claim; often flagged as misleading'),
  ('100% effective',          'fail', 'Absolute efficacy claim; misleading'),
  ('no side effects',         'fail', 'Unverifiable health claim'),
  ('lose weight fast',        'fail', 'Weight-loss claims are restricted in ads and branded content'),
  ('pampapayat',              'warn', 'Slimming claim (Filipino); weight-loss content is restricted'),
  ('fda approved',            'warn', 'Only valid with a real PH FDA registration; remind to verify'),
  ('instant results',         'warn', 'Exaggerated result claim'),
  -- Money and scams
  ('guaranteed income',       'fail', 'Financial guarantee; common scam signal'),
  ('double your money',       'fail', 'Investment scam language'),
  ('get rich quick',          'fail', 'Misleading financial claim'),
  ('guaranteed returns',      'fail', 'Unregistered investment language (PH SEC concern)'),
  ('instant loan approval',   'warn', 'Lending claims are restricted on most platforms'),
  -- Gambling
  ('sabong',                  'fail', 'Online sabong is banned in the Philippines'),
  ('e-sabong',                'fail', 'Online sabong is banned in the Philippines'),
  ('casino',                  'warn', 'Gambling content is restricted; needs licensing and age gating'),
  ('online sugal',            'fail', 'Online gambling promotion (Filipino)'),
  -- Restricted products
  ('vape',                    'warn', 'Tobacco and vape promotion is restricted'),
  ('shabu',                   'fail', 'Illegal drug reference'),
  -- Profanity (Filipino)
  ('putangina',               'fail', 'Profanity; brand safety risk'),
  ('tangina',                 'warn', 'Profanity; brand safety risk'),
  ('gago',                    'warn', 'Insult; brand safety risk'),
  -- Engagement bait
  ('like and share to win',   'warn', 'Engagement bait; platforms reduce reach for this');
