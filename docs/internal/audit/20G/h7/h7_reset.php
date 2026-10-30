<?php
/** 重置 20G-7 库到 H4 结束时的干净状态，供独立旅程复用 */
$root=getenv('GEO_ROOT')?:'D:/734666/GEO OS'; $db=getenv('GEO_DB')?:'D:/Temp/h7_human.sqlite';
putenv("DB_DATABASE=$db"); $_ENV['DB_DATABASE']=$db;
require "$root/vendor/autoload.php"; $a=require "$root/bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.sqlite.database'=>$db]);
Illuminate\Support\Facades\DB::purge('sqlite'); Illuminate\Support\Facades\DB::reconnect('sqlite');
use Illuminate\Support\Facades\DB as D;

// 清理本轮所有测试内容，只保留 H4 的那篇
D::table('contents')->where('slug','!=','h7-human-simulation-article')->delete();
D::table('sync_logs')->delete();
$c = D::table('contents')->where('slug','h7-human-simulation-article')->first();
if (! $c) {
    $catId = D::table('categories')->where('slug','knowledge')->value('id');
    D::table('contents')->insert([
        'site_id'=>1,'type'=>'article','category_id'=>$catId,
        'title'=>'H7 运营闭环验证文章','slug'=>'h7-human-simulation-article',
        'summary'=>'这是 20G-7 人类模拟中由运营人员创建的文章摘要。',
        'body'=>"## 第一节\n\n这是正文第一段，用于验证内容保存与渲染。\n\n- 要点一\n- 要点二\n",
        'status'=>'draft',
        'geo_conclusion'=>'结论：GEO Website OS 的运营闭环可用。',
        'geo_explanation'=>'解释：通过 H1–H8 旅程逐层验证。',
        'geo_boundary'=>'边界：仅验证单站内容生命周期。',
        'geo_evidence'=>json_encode([
            ['label'=>'环境','value'=>'SQLite 41 迁移','source'=>'迁移日志'],
            ['label'=>'回归','value'=>'87 文件全绿','source'=>'CI 输出'],
        ], JSON_UNESCAPED_UNICODE),
        'owner'=>'运营人员','reviewed_at'=>'2026-09-20',
        'content_hash'=>hash('sha256','h7-reset'),
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    echo "  已重建 H4 内容\n";
} else {
    D::table('contents')->where('id',$c->id)->update([
        'status'=>'draft','lock_manual'=>0,'external_id'=>null,
        'title'=>'H7 运营闭环验证文章',
        'summary'=>'这是 20G-7 人类模拟中由运营人员创建的文章摘要。',
        'geo_evidence'=>json_encode([
            ['label'=>'环境','value'=>'SQLite 41 迁移','source'=>'迁移日志'],
            ['label'=>'回归','value'=>'87 文件全绿','source'=>'CI 输出'],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    echo "  已复位 H4 内容\n";
}
D::table('content_revisions')->where('content_id','!=',D::table('contents')->value('id'))->delete();
echo "  contents=" . D::table('contents')->count() . " sync_logs=" . D::table('sync_logs')->count() . "\n";
