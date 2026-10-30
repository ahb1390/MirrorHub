-- MirrorHub schema upgrade
-- 003: Add independent endpoints to mirrors without removing legacy mirrors.url.
--
-- Safe rollout:
-- 1. Take a database backup before applying this migration.
-- 2. Apply this file once to the existing MirrorHub database.
-- 3. Existing mirrors.url values are copied to a primary endpoint.
-- 4. The legacy mirrors.url column remains intact for backward compatibility.

CREATE TABLE IF NOT EXISTS mirror_endpoints (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mirror_id INT NOT NULL,
    label_fa VARCHAR(120) NOT NULL DEFAULT 'آدرس اصلی',
    label_en VARCHAR(120) DEFAULT NULL,
    kind VARCHAR(32) NOT NULL DEFAULT 'repository',
    url VARCHAR(1000) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mirror_endpoints_order (mirror_id, is_active, sort_order, id),
    INDEX idx_mirror_endpoints_primary (mirror_id, is_primary),
    CONSTRAINT fk_mirror_endpoints_mirror
        FOREIGN KEY (mirror_id) REFERENCES mirrors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotent backfill: rerunning the migration will not duplicate an endpoint
-- for the same mirror and URL. Existing mirror records are never modified.
INSERT INTO mirror_endpoints
    (mirror_id, label_fa, label_en, kind, url, is_primary, is_active, sort_order)
SELECT
    m.id,
    'آدرس اصلی',
    'Primary',
    'repository',
    m.url,
    1,
    CASE WHEN m.status = 'active' THEN 1 ELSE 0 END,
    0
FROM mirrors AS m
WHERE m.url IS NOT NULL
  AND m.url <> ''
  AND NOT EXISTS (
      SELECT 1
      FROM mirror_endpoints AS e
      WHERE e.mirror_id = m.id
        AND e.url = m.url
  );
