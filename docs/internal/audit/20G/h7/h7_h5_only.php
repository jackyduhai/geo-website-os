<?php
$root=getenv('GEO_ROOT')?:'D:/734666/GEO OS'; $db=getenv('GEO_DB')?:'D:/Temp/h7_human.sqlite';
putenv("DB_DATABASE=$db"); $_ENV['DB_DATABASE']=$db; $_SERVER['DB_DATABASE']=$db;
require "$root/vendor/autoload.php"; $app=require "$root/bootstrap/app.php";
$k=$app->make(Illuminate\Contracts\Http\Kernel::class); $k->bootstrap();
config(['database.connections.sqlite.database'=>$db,'site.default_fallback'=>true]);
use Illuminate\Support\Facades\DB;
function hit($m,$u,$d=[],$s=[],$c=null){
  $bearer=$s['__bearer']??null; unset($s['__bearer']);
  $req=Illuminate\Http\Request::create($u,$m,[],[],[],
    $s+['HTTP_ACCEPT'=>'text/html','CONTENT_TYPE'=>'application/json'],
    $d===[]?null:json_encode($d,JSON_UNESCAPED_UNICODE));
  if($c)$req->headers->set('Cookie',$c);
  if($bearer)$req->headers->set('Authorization','Bearer '.$bearer);
  $res=app(Illuminate\Contracts\Http\Kernel::class)->handle($req);
  $sc=''; foreach($res->headers->getCookies() as $x)$sc.=$x->getName().'='.$x->getValue().'; ';
  return ['code'=>$res->getStatusCode(),'body'=>$res->getContent(),'loc'=>$res->headers->get('Location'),'cookie'=>trim($sc)];
}
function tok($h){ if(preg_match('/name="_token"\s+value="([^"]+)"/',$h,$m))return $m[1]; return ''; }
App\Support\SiteContext::clear();

$cid=DB::table('contents')->where('slug','h7-human-simulation-article')->value('id');
echo "[内容] id=$cid status=".DB::table('contents')->where('id',$cid)->value('status')."\n";

$lp=hit('GET','/admin/login',[],['HTTP_HOST'=>'a.h7.test']);
$lr=hit('POST','/admin/login',['email'=>'admin@example.com','password'=>'Admin@123456','_token'=>tok($lp['body'])],['HTTP_HOST'=>'a.h7.test'],$lp['cookie']);
$jar=$lr['cookie']?:$lp['cookie'];
echo "[登录] {$lr['code']}\n";

$ed=hit('GET',"/admin/contents/{$cid}/edit",[],['HTTP_HOST'=>'a.h7.test'],$jar);
$catId=(int)DB::table('categories')->where('slug','knowledge')->value('id');
echo "[编辑页] {$ed['code']} category_id=$catId\n";

$payload=['_token'=>tok($ed['body']),'type'=>'article','title'=>'人工精修标题（锁定验证）',
 'slug'=>'h7-human-simulation-article','summary'=>'人工精修摘要。','body'=>"## 精修\n\n人工正文。\n",
 'category_id'=>$catId,'geo_conclusion'=>'人工结论','geo_explanation'=>'人工解释','geo_boundary'=>'人工边界',
 'owner'=>'运营人员','reviewed_at'=>'2026-09-20',
 'ev_label'=>['E1','E2'],'ev_value'=>['0'=>'v1','1'=>'v2'],'ev_source'=>['0'=>'s1','1'=>'s2'],'ev_url'=>['',''],
 'lock_manual'=>'1','action'=>'save'];
$r=hit('PUT',"/admin/contents/{$cid}",$payload,['HTTP_HOST'=>'a.h7.test'],$jar);
echo "[PUT] {$r['code']} loc=".($r['loc']??'-')."\n";
$a=DB::table('contents')->where('id',$cid)->first();
echo "  title=".mb_substr($a->title,0,26)." lock=".var_export($a->lock_manual,true)."\n";

// GEOFlow 冲突验证
DB::table('contents')->where('id',$cid)->update(['external_id'=>'H7-L5-001']);
App\Models\Setting::set('sync_geoflow_token','t5'); App\Models\Setting::set('sync_geoflow_enabled','1'); App\Models\Setting::set('sync_auto_publish','1');
$push=['external_id'=>'H7-L5-001','type'=>'article','title'=>'上游覆盖尝试','slug'=>'h7-human-simulation-article',
 'summary'=>'上游摘要','body'=>'上游正文','category_slug'=>'knowledge','geo_conclusion'=>'c','geo_explanation'=>'e',
 'geo_boundary'=>'b','geo_evidence'=>[['label'=>'E1','value'=>'v','source'=>'s'],['label'=>'E2','value'=>'v2','source'=>'s2']],
 'owner'=>'GEOFlow','reviewed_at'=>'2026-09-25'];
$r2=hit('POST','/api/v1/geoflow/contents',$push,['HTTP_HOST'=>'a.h7.test','__bearer'=>'t5']);
echo "\n[GEOFlow upsert] {$r2['code']} → ".mb_substr($r2['body'],0,110)."\n";
$r3=hit('POST','/api/v1/geoflow/unpublish',['external_id'=>'H7-L5-001'],['HTTP_HOST'=>'a.h7.test','__bearer'=>'t5']);
echo "[GEOFlow unpublish] {$r3['code']} → ".mb_substr($r3['body'],0,110)."\n";
$b=DB::table('contents')->where('id',$cid)->first();
echo "\n[最终] title=".mb_substr($b->title,0,26)." status={$b->status} lock=".var_export($b->lock_manual,true)."\n";
$pass = ($a->lock_manual==1) && ($r2['code']===409) && ($r3['code']===409) && str_contains($b->title,'人工精修');
echo "\n判定: ".($pass?'✅ H5 PASS':'❌ H5 FAIL')."\n";
