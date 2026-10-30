<?php
/** Backward-compatible mirror endpoint persistence helpers. */
function mhMirrorEndpointsAvailable(PDO $pdo): bool { return function_exists('hasTable') && hasTable($pdo, 'mirror_endpoints'); }
function mhValidateEndpointUrl($value): ?string {
    $url = trim((string)$value);
    if ($url === '' || strlen($url) > 1000 || preg_match('/[\x00-\x20\x7f]/', $url)) return null;
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return null;
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
    if (!in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https', 'ftp', 'rsync'], true)) return null;
    return $url;
}
function mhGetMirrorEndpoints(PDO $pdo, int $mirrorId): array {
    if ($mirrorId < 1 || !mhMirrorEndpointsAvailable($pdo)) return [];
    $stmt = $pdo->prepare("SELECT id, mirror_id, label_fa, label_en, kind, url, is_primary, is_active, sort_order FROM mirror_endpoints WHERE mirror_id = ? ORDER BY is_primary DESC, sort_order ASC, id ASC");
    $stmt->execute([$mirrorId]); return $stmt->fetchAll();
}
/** Caller owns the transaction. Legacy mirrors.url remains the primary URL. */
function mhSaveMirrorEndpoints(PDO $pdo, int $mirrorId, string $primaryUrl, array $extraUrls, array $extraLabels, bool $active): bool {
    if ($mirrorId < 1) throw new InvalidArgumentException('شناسه میرور معتبر نیست.');
    $primary = mhValidateEndpointUrl($primaryUrl);
    if ($primary === null) throw new InvalidArgumentException('آدرس اصلی باید URL معتبر با پروتکل HTTP، HTTPS، FTP یا Rsync باشد.');
    $normalized = [['url' => $primary, 'label_fa' => 'آدرس اصلی', 'is_primary' => 1]];
    $seen = [strtolower($primary) => true];
    foreach (array_values($extraUrls) as $index => $rawUrl) {
        $rawUrl = trim((string)$rawUrl); if ($rawUrl === '') continue;
        $validUrl = mhValidateEndpointUrl($rawUrl);
        if ($validUrl === null) throw new InvalidArgumentException('یکی از آدرس‌های جایگزین معتبر نیست: ' . mb_substr($rawUrl, 0, 120));
        $key = strtolower($validUrl); if (isset($seen[$key])) throw new InvalidArgumentException('آدرس تکراری برای یک میرور مجاز نیست.');
        $seen[$key] = true; $label = trim((string)($extraLabels[$index] ?? ''));
        if (mb_strlen($label) > 120) throw new InvalidArgumentException('برچسب هر آدرس حداکثر می‌تواند ۱۲۰ نویسه باشد.');
        $normalized[] = ['url' => $validUrl, 'label_fa' => $label !== '' ? $label : 'آدرس جایگزین ' . count($normalized), 'is_primary' => 0];
    }
    if (!mhMirrorEndpointsAvailable($pdo)) {
        if (count($normalized) > 1) throw new RuntimeException('برای ثبت چند آدرس، ابتدا مهاجرت ۰۰۳ (mirror_endpoints) را اجرا کنید.');
        return false;
    }
    $pdo->prepare("DELETE FROM mirror_endpoints WHERE mirror_id = ?")->execute([$mirrorId]);
    $insert = $pdo->prepare("INSERT INTO mirror_endpoints (mirror_id, label_fa, label_en, kind, url, is_primary, is_active, sort_order) VALUES (?, ?, NULL, 'repository', ?, ?, ?, ?)");
    foreach ($normalized as $order => $endpoint) $insert->execute([$mirrorId, $endpoint['label_fa'], $endpoint['url'], $endpoint['is_primary'], $active ? 1 : 0, $order]);
    return true;
}

/**
 * Fetch active endpoints for a collection of mirrors in one query.
 * Legacy mirrors remain visible when the migration is not installed or
 * when a mirror has no endpoint rows yet.
 */
function mhGetMirrorEndpointsMap(PDO $pdo, array $mirrorIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $mirrorIds), static fn($id) => $id > 0)));
    if (!$ids) return [];
    $map = [];
    foreach ($ids as $id) $map[$id] = [];
    if (mhMirrorEndpointsAvailable($pdo)) {
        $in = implode(',', $ids);
        try {
            $stmt = $pdo->query("SELECT id, mirror_id, label_fa, label_en, kind, url, is_primary, is_active, sort_order
                FROM mirror_endpoints
                WHERE mirror_id IN ($in) AND is_active = 1
                ORDER BY mirror_id ASC, is_primary DESC, sort_order ASC, id ASC");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[(int)$row['mirror_id']][] = $row;
            }
        } catch (Throwable $e) {
            // Fall back to the legacy URL below if the optional table is unavailable.
        }
    }
    return $map;
}

/** Return active public endpoints, falling back to mirrors.url for legacy rows. */
function mhPublicMirrorEndpoints(PDO $pdo, array $mirror): array {
    $id = (int)($mirror['id'] ?? 0);
    $map = mhGetMirrorEndpointsMap($pdo, [$id]);
    $rows = $map[$id] ?? [];
    if ($rows) return $rows;
    $url = trim((string)($mirror['url'] ?? ''));
    if ($url === '') return [];
    return [[
        'id' => null,
        'mirror_id' => $id,
        'label_fa' => 'آدرس اصلی',
        'label_en' => 'Primary',
        'kind' => 'repository',
        'url' => $url,
        'is_primary' => 1,
        'is_active' => 1,
        'sort_order' => 0,
    ]];
}
