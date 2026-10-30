<?php
$db = getenv('GEO_DB') ?: 'D:/Temp/gw_e2e.sqlite';
$_ENV['DB_DATABASE']=$db; putenv("DB_DATABASE=$db");
require getenv('GEO_ROOT') . '/vendor/autoload.php';
$app=require_once getenv('GEO_ROOT') . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.sqlite.database'=>$db]);
Illuminate\Support\Facades\DB::purge('sqlite');

use App\Models\{Site,Entity,Content,Category,Fact,Setting};
use App\Support\{SiteContext,Catalog};

$A='AAA-CANARY-A'; $B='BBB-CANARY-B';

$siteA = Site::where('slug','default')->firstOrFail();
$siteA->update(['domain'=>'a.test','name'=>'A 站公司','status'=>'active']);
$siteB = Site::create(['slug'=>'site-b','name'=>'B 站公司','domain'=>'b.test','status'=>'active','is_default'=>false]);

function seedFor(Site $s, string $canary, string $pname, string $slug) {
    SiteContext::setSite($s); Catalog::flush();
    Entity::create(['type'=>'organization','name'=>$canary,'slug'=>'e2e-org','status'=>'published',
        'metadata'=>['is_default'=>true,'company'=>['name'=>$canary,'short'=>$canary],
                     'product_lines'=>[['slug'=>'both-lines','name'=>$canary.' 系列']]]]);
    Entity::create(['type'=>'product','name'=>$pname,'slug'=>'both-products','status'=>'published',
        'summary'=>$canary.' 摘要','metadata'=>['core'=>true,'line'=>'both-lines']]);
    $k = Category::where('slug','knowledge')->first();
    Content::create(['type'=>'article','category_id'=>$k?->id,'title'=>$canary.' 文章',
        'slug'=> $slug,
        'status'=>'published','summary'=>$canary.' 摘要','body'=>$canary.' 正文',
        'published_at'=>now()->subDays(5)]);
    Fact::updateOrCreate(['site_id'=>$s->id,'key'=>'FACT-E2E-SHARED'],
        ['label'=>$canary.' 标签','value'=>$canary,'group'=>'e2e','is_public'=>true,'sort'=>999]);
}

seedFor($siteA, $A, 'A 站专属产品', 'a-only-article');
seedFor($siteB, $B, 'B 站专属产品', 'b-only-article');

SiteContext::setSite($siteA); Catalog::flush();
echo "\n种子完成\n";
echo "  A 站 #{$siteA->id} domain=a.test\n";
echo "  B 站 #{$siteB->id} domain=b.test\n";
foreach (['a.test'=>$siteA,'b.test'=>$siteB] as $d=>$s) {
    SiteContext::setSite($s); Catalog::flush();
    echo "  {$d}: entities=".Entity::count()." contents=".Content::count()." facts=".Fact::count()
       ." company=".json_encode(Catalog::company()['name'] ?? '(空)', JSON_UNESCAPED_UNICODE)."\n";
}
