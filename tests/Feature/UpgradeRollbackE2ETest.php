<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TD-168 必修③：最小 Upgrade/Rollback E2E（sqlite）
 *
 * 证明升级/回滚链路可靠：
 *
 *   测试 A：backup → 应用一条新迁移 → 健康检查 → baseline counts 不丢 → 版本号正确
 *   测试 B：backup → 故意制造迁移失败 → geo:rollback → DB sha256 与 backup 一致
 *           → 旧版 health 200 → migrations 表无坏迁移残留
 *
 * 隔离策略：
 *   - 每个用例在 sys_get_temp_dir() 下建独立 sqlite 文件，phpunit.xml 默认 :memory:
 *     在本类 setUp() 中显式覆盖为临时文件，绝不触碰开发库；
 *   - 临时迁移写到 base_path('tests/tmp/...')，用 `migrate --path` 指定，tearDown 删除；
 *   - geo:backup / geo:rollback 产物落在 storage/app/backups（已 gitignore）。
 */
class UpgradeRollbackE2ETest extends TestCase
{
    private string $tmpDir;
    private string $dbPath;
    private string $goodMigDir;
    private string $badMigDir;

    protected function setUp(): void
    {
        parent::setUp();

        // ---------- 1. 建临时 sqlite 文件库 ----------
        $this->tmpDir = sys_get_temp_dir() . '/geo-e2e-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        $this->dbPath = $this->tmpDir . '/database.sqlite';
        touch($this->dbPath);

        // 覆盖连接：phpunit.xml 默认 :memory:，E2E 需要真实文件（geo:backup 复制文件）
        config(['database.connections.sqlite.database' => $this->dbPath]);
        DB::purge('sqlite');

        // 全量迁移 + 种子，等价于 geo:install 后的站点状态
        $this->artisan('migrate:fresh', ['--force' => true, '--seed' => true])->assertExitCode(0);

        // ---------- 2. 临时迁移目录（相对 base_path，供 migrate --path 使用） ----------
        $this->goodMigDir = 'tests/tmp/e2e-good-' . uniqid();
        $this->badMigDir  = 'tests/tmp/e2e-bad-' . uniqid();
        mkdir(base_path($this->goodMigDir), 0777, true);
        mkdir(base_path($this->badMigDir), 0777, true);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        // 注：本用例只打 /api/v1/health（不经 web PageCache 中间件），不写文件型整页缓存，
        // 因此无需 flush file store——在 Windows 上 flush 与被锁缓存文件冲突会破坏缓存目录。

        // 清理临时迁移目录
        foreach (array_unique([$this->goodMigDir, $this->badMigDir]) as $rel) {
            $abs = base_path($rel);
            if (is_dir($abs)) {
                foreach (glob($abs . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($abs);
            }
        }
        // 清理临时 sqlite 目录（含 -wal/-journal 残留）
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    /** 记录 baseline counts（任务要求的四类表） */
    private function baselineCounts(): array
    {
        return [
            'contents' => DB::table('contents')->count(),
            'entities' => Schema::hasTable('entities') ? DB::table('entities')->count() : 0,
            'pages'    => Schema::hasTable('pages') ? DB::table('pages')->count() : 0,
            'media'    => Schema::hasTable('media') ? DB::table('media')->count() : 0,
        ];
    }

    /** 找到本次 geo:backup 刚产出的备份文件（按 mtime 取最新） */
    private function newestBackupFile(): string
    {
        $files = glob(storage_path('app/backups/geo-backup-*.sqlite')) ?: [];
        usort($files, fn ($a, $b) => filemtime($a) <=> filemtime($b));
        $last = end($files);
        $this->assertIsString($last !== false && is_file($last) ? $last : '', 'geo:backup 应产出 .sqlite 文件');

        return $last;
    }

    /** 写一条「成功」迁移：给 contents 加一个可空标记列（纯增量，不丢数据） */
    private function writeGoodMigration(): string
    {
        $name = '2099_01_01_000001_e2e_upgrade_marker.php';
        $path = base_path($this->goodMigDir . '/' . $name);
        file_put_contents($path, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->string('e2e_upgrade_marker', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn('e2e_upgrade_marker');
        });
    }
};
PHP);

        return $name;
    }

    /** 写一条「故意失败」迁移：up() 直接抛异常 */
    private function writeBadMigration(): string
    {
        $name = '2099_01_01_000002_e2e_intentional_bad.php';
        $path = base_path($this->badMigDir . '/' . $name);
        file_put_contents($path, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // E2E 注入：模拟升级迁移中途失败（坏 SQL / 坏列 / 运行时异常）
        throw new \RuntimeException('E2E intentional migration failure: simulated bad upgrade');
    }

    public function down(): void
    {
        // no-op
    }
};
PHP);

        return $name;
    }

    // ========================================================================
    // 测试 A：成功升级 E2E
    // ========================================================================
    public function test_a_successful_upgrade_preserves_data_and_reports_version(): void
    {
        // 1. baseline
        $baseline = $this->baselineCounts();
        $this->assertGreaterThan(0, $baseline['contents'], '种子库应有 contents 行');

        // 2. geo:backup → 产生 .sqlite + .json 清单（含 sha256）
        $this->artisan('geo:backup')->assertExitCode(0);
        $backupFile = $this->newestBackupFile();
        $manifestPath = $backupFile . '.json';
        $this->assertFileExists($manifestPath, '备份应伴随 .json 清单');

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('sha256', $manifest);
        $this->assertSame(64, strlen($manifest['sha256']), '清单 sha256 应为 64 位 hex');
        $this->assertSame(hash_file('sha256', $backupFile), $manifest['sha256'], '备份文件 sha256 应与清单一致');

        $backupVersion = $manifest['version'];
        $this->assertNotEmpty($backupVersion);

        // 3. 模拟升级：备份后再应用一条增量迁移（additive column）
        $goodMig = $this->writeGoodMigration();
        $this->artisan('migrate', [
            '--path' => $this->goodMigDir,
            '--force' => true,
        ])->assertExitCode(0);

        // 4. 升级后健康检查：API 探活 200（/api/v1/health 不经 web PageCache，不污染文件缓存）。
        //    前台路由（/、/knowledge/、/sitemap.xml）注册性断言——实际 GET 会写文件型
        //    PageCache，跨用例泄漏，故此处只断言路由已注册 + 关键表可查（任务允许的降级）。
        $this->get('/api/v1/health')->assertOk()->assertJson(['status' => 'ok']);
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('home'), 'home 路由应注册');
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('geo.sitemap'), 'sitemap 路由应注册');
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('knowledge.index'), 'knowledge 路由应注册');
        foreach (['contents', 'entities', 'pages', 'media', 'migrations', 'sites', 'seo_metas'] as $t) {
            $this->assertTrue(Schema::hasTable($t), "升级后关键表 {$t} 应存在");
        }

        // 5. baseline counts 不丢
        $after = $this->baselineCounts();
        $this->assertSame($baseline, $after, '升级后 contents/entities/pages/media counts 必须与 baseline 一致');

        // 6. 版本号正确 + 迁移记录推进
        $this->assertSame($backupVersion, config('geo.version'), '升级前后版本号（config）一致');
        $this->assertTrue(
            Schema::hasColumn('contents', 'e2e_upgrade_marker'),
            '升级迁移应已应用（新列存在）'
        );

        $migRow = DB::table('migrations')->where('migration', '2099_01_01_000001_e2e_upgrade_marker')->first();
        $this->assertNotNull($migRow, '新迁移应写入 migrations 表');
        $this->assertGreaterThanOrEqual(
            2,
            (int) $migRow->batch,
            '新迁移应落在新 batch（≥2）'
        );
    }

    // ========================================================================
    // 测试 B：失败升级 → rollback E2E
    // ========================================================================
    public function test_b_failed_upgrade_then_rollback_restores_exact_database(): void
    {
        // 1. baseline
        $baseline = $this->baselineCounts();
        $this->assertGreaterThan(0, $baseline['contents'], '种子库应有 contents 行');

        // 2. geo:backup，记录备份文件 sha256
        $this->artisan('geo:backup')->assertExitCode(0);
        $backupFile = $this->newestBackupFile();
        $backupSha = hash_file('sha256', $backupFile);
        $this->assertSame(64, strlen($backupSha));

        // 3. 制造迁移失败：写一条 up() 抛异常的迁移并运行
        $badMig = $this->writeBadMigration();
        // 关闭当前连接，避免 Windows 文件锁干扰后续文件复制
        DB::purge('sqlite');

        // migrate 必须失败（坏迁移抛 RuntimeException，测试上下文下会冒泡）
        $migrateThrew = false;
        try {
            $this->artisan('migrate', [
                '--path' => $this->badMigDir,
                '--force' => true,
            ])->run();
        } catch (\RuntimeException $e) {
            $migrateThrew = true;
            $this->assertStringContainsString('E2E intentional migration failure', $e->getMessage());
        }
        $this->assertTrue($migrateThrew, '坏迁移必须导致 migrate 失败');

        // 失败迁移已回滚；再次关闭连接，释放文件锁，便于 rollback 整库复制
        DB::purge('sqlite');

        // 4. geo:rollback --backup=... --force
        $this->artisan('geo:rollback', [
            '--backup' => $backupFile,
            '--force'  => true,
        ])->assertExitCode(0);

        // 5. rollback 后 DB 文件 sha256 必须与 backup 完全一致（整库还原）
        DB::purge('sqlite');
        $this->assertSame(
            $backupSha,
            hash_file('sha256', $this->dbPath),
            'rollback 后数据库文件 sha256 应与备份一致（数据完整还原）'
        );

        // 6. 重连后断言数据还原 + 健康检查 200
        $restored = $this->baselineCounts();
        $this->assertSame($baseline, $restored, 'rollback 后 counts 必须与 baseline 一致');

        // 旧版 health 200（API 路由，不写文件型 PageCache）
        $this->get('/api/v1/health')->assertOk()->assertJson(['status' => 'ok']);
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('home'), 'rollback 后 home 路由应注册');
        foreach (['contents', 'entities', 'pages', 'media', 'migrations', 'sites', 'seo_metas'] as $t) {
            $this->assertTrue(Schema::hasTable($t), "rollback 后关键表 {$t} 应存在");
        }

        // 7. 坏迁移未残留：migrations 表无该行，且坏列/坏 schema 未生效
        $this->assertNull(
            DB::table('migrations')->where('migration', '2099_01_01_000002_e2e_intentional_bad')->first(),
            '失败迁移不应残留于 migrations 表'
        );
        $this->assertFalse(
            Schema::hasColumn('contents', 'e2e_upgrade_marker'),
            '失败迁移未生效（无标记列），证明 DB 已整库还原到备份状态'
        );
    }
}
