<?php
/**
 * 20G-6 · L0 业务数据播种（升级前的真实业务状态）
 * 关键构造：双站使用**相同slug、不同内容** —— 最隐蔽的跨站污染形态（20G-5 已验证有效）
 */

$root = getenv('GEO_ROOT') ?: 'D:/734666/GEO OS';
$db = getenv('GEO_DB');

putenv("DB_DATABASE=$db"); $_ENV['DB_DATABASE'] = $db; $_SERVER['DB_DATABASE'] = $db;
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.sqlite.database' => $db]);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\DB::reconnect('sqlite');

use App\Models\{Site, Entity, Content, Category, Fact, Setting};
use App\Support\{SiteContext, Catalog};
use Illuminate\Support\Facades\DB as DBF;

$A = 'GW6-CANARY-A';
$B = 'GW6-CANARY-B';

$siteA = Site::where('slug', 'default')->firstOrFail();
$siteA->update(['domain' => 'a.gw6.test', 'name' => 'GW6 A 站', 'status' => 'active']);
$siteB = Site::where('domain', 'b.gw6.test')->first()
    ?: Site::create(['slug' => 'gw6-b', 'name' => 'GW6 B 站', 'domain' => 'b.gw6.test',
                     'status' => 'active', 'is_default' => false]);

function seedFor(Site $s, string $canary, string $articleSlug): void
{
    SiteContext::setSite($s);
    Catalog::flush();

    Entity::create(['type' => 'organization', 'name' => $canary . ' 公司', 'slug' => 'shared-org-slug',
        'status' => 'published', 'summary' => $canary . ' 组织',
        'metadata' => ['is_default' => true,
            'company' => ['name' => $canary . ' 公司', 'short' => $canary],
            'product_lines' => [['slug' => 'shared-line', 'name' => $canary . ' 系列']]]]);
    Entity::create(['type' => 'product', 'name' => $canary . ' 产品', 'slug' => 'shared-product-slug',
        'status' => 'published', 'summary' => $canary . ' 产品摘要',
        'metadata' => ['core' => true, 'line' => 'shared-line']]);
    Entity::create(['type' => 'product', 'name' => $canary . ' 系列', 'slug' => 'shared-line',
        'status' => 'published', 'summary' => $canary . ' 系列页', 'metadata' => ['is_line' => true]]);
    Entity::create(['type' => 'service', 'name' => $canary . ' 场景', 'slug' => 'shared-scene',
        'status' => 'published', 'summary' => $canary . ' 场景', 'metadata' => ['combo' => ['shared-product-slug']]]);

    $k = Category::where('slug', 'knowledge')->first();
    Content::create(['type' => 'article', 'category_id' => $k?->id,
        'title' => $canary . ' 文章', 'slug' => $articleSlug, 'status' => 'published',
        'summary' => $canary . ' 摘要', 'body' => $canary . ' 正文',
        'geo_conclusion' => $canary . ' 结论', 'geo_explanation' => $canary . ' 解释',
        'geo_boundary' => $canary . ' 边界',
        'geo_evidence' => [['label' => 'E1', 'value' => $canary . '-v1', 'source' => '台账'],
                           ['label' => 'E2', 'value' => $canary . '-v2', 'source' => '实拍']],
        'owner' => 'GEOFlow', 'reviewed_at' => '2026-09-14', 'published_at' => now()->subDays(20)]);

    Fact::updateOrCreate(['site_id' => $s->id, 'key' => 'GW6_SHARED_FACT'],
        ['label' => $canary . ' 标签', 'value' => $canary, 'group' => 'gw6', 'is_public' => true, 'sort' => 900]);

    Setting::set('gw6_marker_' . $s->slug, $canary);
    Catalog::flush();
}

seedFor($siteA, $A, 'gw6-a-only-article');
seedFor($siteB, $B, 'gw6-b-only-article');

// 让两站 slug 完全撞车（最隐蔽污染形态）：A 站也建一个同名 entity
// 上面已用相同 slug（shared-org-slug 等），此处再显式验证 A 站独立条目
SiteContext::setSite($siteA);
Catalog::flush();
Entity::create(['type' => 'case_study', 'name' => $A . ' 案例', 'slug' => 'shared-case',
    'status' => 'published', 'summary' => $A . ' 案例', 'metadata' => []]);

echo "\nL0 业务数据播种完成\n";
echo "  A 站 #{$siteA->id} domain=a.gw6.test\n";
echo "  B 站 #{$siteB->id} domain=b.gw6.test\n";
foreach ([$siteA, $siteB] as $s) {
    SiteContext::setSite($s); Catalog::flush();
    printf("  %s: entities=%d contents=%d facts=%d settings=%d\n",
        $s->domain, Entity::count(), Content::count(), Fact::count(), Setting::count());
}
echo "  同 slug 跨站共存：shared-org-slug / shared-product-slug / shared-line / shared-scene\n";