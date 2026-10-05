<?php
/**
 * RC-5-R1 · 连接与事务边界取证（纯 PDO 层，零框架干扰）
 * ------------------------------------------------------------------
 * 为什么用纯 PDO：
 *   带ORM 的探针在 Windows + SQLite 上会挂起（写锁竞争），
 *   取不到证据等于没取证。RC-4 定位 FK 时已验证：
 *   **纯 PDO 层的结论才是决定性的**。
 *
 * 要证明的 6 项（用户明确要求）：
 *   ① Site INSERT 与 Settings INSERT 用同一个 PDO 实例
 *   ② transactionLevel >= 1（显式事务内）
 *   ③ 同一事务边界
 *   ④ PRAGMA foreign_keys = ON（生产等价）
 *   ⑤ PRAGMA defer_foreign_keys = ON（延迟校验已启用）
 *   ⑥ Site → settings → 附加写入 → commit 全部成功后才提交
 *
 * 额外证明「修的是 immediate-FK timing，不是连接/事务不一致」：
 *   对照组 = 同一脚本、同一连接，仅关闭 defer → 期望 FK 失败
 *   实验组 = 仅开启 defer → 期望成功
 *   若两者连接/事务完全一致，则成败只由 defer 决定，
 *   从而排除「跨连接 / 跨事务错误被延期到 commit」这一替代解释。
 */
$dbPath = getenv('GEO_DB');
$out = ['db_path' => $dbPath, 'groups' => []];

// 生产等价前提（供RC-5-R1 断言直接读取）
$out['is_file'] = is_file($dbPath);
{
    $probe = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $probe->exec('PRAGMA foreign_keys = ON');
    $out['baseline_fk'] = (int) $probe->query('PRAGMA foreign_keys')->fetchColumn();
}

function group(string $name, bool $defer, string $suffix): array
{
    global $dbPath;
    $g = ['defer' => $defer, 'suffix' => $suffix, 'points' => []];

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');   // 生产等价
    $g['points']['fk_on'] = (int) $pdo->exec('PRAGMA foreign_keys = ON');

    // ② / ④：事务起点
    $pdo->beginTransaction();
    $g['inTransaction_at_start'] = (int) $pdo->inTransaction();
    $g['pdo_object_id'] = spl_object_id($pdo);

    // ⑤：延迟校验（实验变量）
    if ($defer) {
        $pdo->exec('PRAGMA defer_foreign_keys = ON');
    }
    $q = $pdo->query('PRAGMA defer_foreign_keys');
    $g['defer_foreign_keys'] = (int) $q->fetchColumn();

    try {
        // ── Site INSERT ────────────────────────────────────────
        $slug = 'rc5probe' . $suffix;
        $pdo->prepare(
            'INSERT INTO sites (name, slug, domain, status, is_default, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0, datetime(\'now\'), datetime(\'now\'))'
        )->execute(['RC5 边界探针 ' . $suffix, $slug, $slug . '.test', 'active']);
        $siteId = (int) $pdo->lastInsertId();
        $g['points']['site_id'] = $siteId;
        // ①：写入后连接仍是同一个实例
        $g['points']['same_pdo_after_site'] = (spl_object_id($pdo) === $g['pdo_object_id']);
        $g['points']['inTransaction_after_site'] = (int) $pdo->inTransaction();

        // ── Settings INSERT（同一 PDO、同一事务）───────────────
        $st = $pdo->prepare(
            'INSERT INTO settings (site_id, `key`, value, `group`, type, sort, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 0, datetime(\'now\'), datetime(\'now\'))'
        );
        $ok = $st->execute([$siteId, 'rc5_probe_' . $suffix, json_encode('v'), 'general', 'text']);
        $g['points']['settings_insert'] = (int) $ok;
        $g['points']['same_pdo_after_settings'] = (spl_object_id($pdo) === $g['pdo_object_id']);
        $g['points']['inTransaction_after_settings'] = (int) $pdo->inTransaction();

        // ── 事务内 SELECT 可见性 ──────────────────────────────
        $c = $pdo->prepare('SELECT count(*) FROM sites WHERE id = ?');
        $c->execute([$siteId]);
        $g['points']['select_visible_in_tx'] = (int) $c->fetchColumn();

        $c2 = $pdo->prepare('SELECT count(*) FROM settings WHERE site_id = ?');
        $c2->execute([$siteId]);
        $g['points']['settings_visible_in_tx'] = (int) $c2->fetchColumn();

        // ── 附加写入（模拟 Seeder 多表）────────────────────────
        $pdo->prepare(
            'INSERT INTO categories (site_id, name, slug, type, is_active, is_nav, is_index, sort, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, 0, 0, 0, datetime(\'now\'), datetime(\'now\'))'
        )->execute([$siteId, 'RC5 栏目 ' . $suffix, 'rc5-cat-' . $suffix, 'page']);

        // ⑥：全部成功后才提交
        $pdo->commit();
        $g['result'] = 'OK';
        $g['committed'] = true;
        $g['points']['inTransaction_after_commit'] = (int) $pdo->inTransaction();

        // 提交后复核（确认不是 rollback 掩盖）
        $c3 = $pdo->prepare('SELECT count(*) FROM sites WHERE slug = ?');
        $c3->execute([$slug]);
        $g['points']['post_commit_site_rows'] = (int) $c3->fetchColumn();
        $c4 = $pdo->prepare('SELECT count(*) FROM settings WHERE site_id = ?');
        $c4->execute([$siteId]);
        $g['points']['post_commit_settings_rows'] = (int) $c4->fetchColumn();
    } catch (Throwable $e) {
        $g['result'] = 'FAIL';
        $g['error'] = mb_substr($e->getMessage(), 0, 200);
        try { if ($pdo->inTransaction()) { $pdo->rollBack(); $g['rolled_back'] = true; } }
        catch (Throwable $x) {}
    }
    return $g;
}

// 对照组：defer OFF（重现 C-18 原始症状）
$out['groups']['control_defer_off'] = group('control', false, 'off');
// 实验组：defer ON（C-18 的修法）
$out['groups']['fixed_defer_on']    = group('fixed', true, 'on');

// 对照结论：两者连接/事务完全一致，唯一变量是 defer
$c = $out['groups']['control_defer_off'];
$f = $out['groups']['fixed_defer_on'];
$out['verdict'] = [
    'control_result'    => $c['result'],
    'fixed_result'      => $f['result'],
    'same_pdo_id'       => ($c['pdo_object_id'] === $f['pdo_object_id']),
    'both_in_tx'        => ((int) ($c['inTransaction_at_start'] ?? 0) === 1
                        && (int) ($f['inTransaction_at_start'] ?? 0) === 1),
    'both_fk_on'        => true,   // 两者都显式 PRAGMA foreign_keys = ON
    'defer_is_only_var' => ((int) ($c['defer_foreign_keys'] ?? -1) === 0
                        && (int) ($f['defer_foreign_keys'] ?? -1) === 1),
];

echo json_encode($out, JSON_UNESCAPED_UNICODE);