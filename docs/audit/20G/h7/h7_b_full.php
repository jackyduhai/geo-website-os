<?php
/** B 站完整链：创建 → 发布 → 前台/GEO 验证（同进程内，不清库） */
$root=getenv('GEO_ROOT')?:'D:/734666/GEO OS'; $db=getenv('GEO_DB')?:'D:/Temp/h7_human.sqlite';
putenv("DB_DATABASE=$db"); $_ENV['DB_DATABASE']=$db; $_SERVER['DB_DATABASE']=$db;
require "$root/vendor/autoload.php"; $app=require "$root/bootstrap/app.php";
$k=$app->make(Illuminate\Contracts\Http\Kernel::class); $k->bootstrap();
config(['database.connections.sqlite.database'=>$db,'site.default_fallback'=>true]);
use Illuminate\Support\Facades\DB;
$P=0;$F=0;
function hit($m,$u,$d=[],$s=[],$c=null){
  $req=Illuminate\Http\Request::create($u,$m,[],[],[],
    $s+['HTTP_ACCEPT'=>'text/html','CONTENT_TYPE'=>'application/json'],
    $d===[]?null:json_encode($d,JSON_UNESCAPED_UNICODE));
  if($c)$req->headers->set('Cookie',$c);
  $res=app(Illuminate\Contracts\Http\Kernel::class)->handle($req);
  $sc=''; foreach($res->headers->getCookies() as $x)$sc.=$x->getName().'='.$x->getValue().'; ';
  return ['code'=>$res->getStatusCode(),'body'=>$res->getContent(),'loc'=>$res->headers->get('Location'),'cookie'=>trim($sc)];
}
function tok($h){ if(preg_match('/name="_token"\s+value="([^"]+)"/',$h,$m))return $m[1]; return ''; }
function ok($m){global $P;$P++;echo "  ✅ $m\n";}
function bad($m){global $P,$F;$F++;echo "  ❌ $m\n";}
function chk($c,$p,$f){$c?ok($p):bad($f);}

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  H8 · B 站完整链（创建 → 发布 → 前台/GEO）                     ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// 清 B 站残留
DB::table('contents')->where('site_id',2)->delete();

App\Support\SiteContext::clear();
$lp=hit('GET','/admin/login',[],['HTTP_HOST'=>'b.h7.test']);
$lr=hit('POST','/admin/login',['email'=>'admin@example.com','password'=>'Admin@123456','_token'=>tok($lp['body'])],['HTTP_HOST'=>'b.h7.test'],$lp['cookie']);
$jar=$lr['cookie']?:$lp['cookie'];
chk($lr['code']===302,'B 站登录成功',"B 站登录失败（{$lr['code']}）");

$pg=hit('GET','/admin/contents/create/article',[],['HTTP_HOST'=>'b.h7.test'],$jar);
$T=tok($pg['body']);
$catB=(int)DB::table('categories')->where('site_id',2)->where('slug','knowledge')->value('id');
echo "     B 站栏目 category_id={$catB}\n";

// 创建
$r=hit('POST','/admin/contents',['type'=>'article','title'=>'B 站独有标题','slug'=>'h7-shared-slug',
  'summary'=>'B 站摘要','body'=>"## B站\n\nB 站正文内容。\n",'category_id'=>$catB,
  'geo_conclusion'=>'B 站结论：独立站点。','geo_explanation'=>'B 站解释：验证多站。','geo_boundary'=>'B 站边界：仅本站。',
  'owner'=>'B 运营','reviewed_at'=>'2026-09-20',
  'ev_label'=>['B1','B2'],'ev_value'=>['0'=>'bv1','1'=>'bv2'],'ev_source'=>['0'=>'bs1','1'=>'bs2'],'ev_url'=>['',''],
  '_token'=>$T],['HTTP_HOST'=>'b.h7.test'],$jar);
chk($r['code']===302,"B 站创建成功（{$r['code']} → ".($r['loc']??'-')."）","B 站创建失败（{$r['code']}）");

$row=DB::table('contents')->where('site_id',2)->orderByDesc('id')->first();
if(!$row){ bad('B 站内容未落库'); exit; }
echo "     #{$row->id} [{$row->status}] {$row->title}\n";
$cidB=$row->id;

// 发布（不 fresh，保留 B 站 session）
App\Support\SiteContext::clear();
$ed=hit('GET',"/admin/contents/{$cidB}/edit",[],['HTTP_HOST'=>'b.h7.test'],$jar);
$r=hit('POST',"/admin/contents/{$cidB}/publish",['_token'=>tok($ed['body'])],['HTTP_HOST'=>'b.h7.test'],$jar);
echo "     发布响应 {$r['code']} loc=".($r['loc']??'-')."\n";
if($r['loc']){
  $red=hit('GET',$r['loc'],[],['HTTP_HOST'=>'b.h7.test'],$jar);
  if(preg_match('/未通过 GEO 门禁，已拒绝发布：([^<]{0,120})/u',$red['body'],$m)) echo "     门禁拒绝: ".trim($m[1])."\n";
  elseif(preg_match('/内容已发布[^<]{0,50}/u',$red['body'],$m2)) echo "     flash: ".trim($m2[0])."\n";
}
$st=DB::table('contents')->where('id',$cidB)->value('status');
chk($st==='published',"B 站发布生效（status={$st}）","B 站发布未生效（status={$st}）");

// 前台
App\Support\SiteContext::clear();
$fB=hit('GET','/h7-shared-slug',[],['HTTP_HOST'=>'b.h7.test']);
if($fB['code']===301&&$fB['loc'])$fB=hit('GET',$fB['loc'],[],['HTTP_HOST'=>'b.h7.test']);
chk(str_contains($fB['body'],'B 站独有标题'),"B 站前台可见自己的内容（{$fB['code']}）","B 站前台看不到自己的内容（{$fB['code']}）");

// GEO
App\Support\SiteContext::clear();
$gB=json_decode(hit('GET','/geo.json',[],['HTTP_HOST'=>'b.h7.test'])['body'],true);
$tB=array_column($gB['contents']??[],'title');
chk(in_array('B 站独有标题',$tB,true),'B 站 geo.json 含自己的内容','B 站 geo.json 缺自己的内容（'.count($tB).' 条）');

// 后台列表
$lB=hit('GET','/admin/contents',[],['HTTP_HOST'=>'b.h7.test'],$jar);
chk(str_contains($lB['body'],'B 站独有标题'),'B 站后台列表可见自己的内容','B 站后台列表看不到');

// A 站负向
App\Support\SiteContext::clear();
$lA=hit('GET','/admin/contents',[],['HTTP_HOST'=>'a.h7.test']);
chk(!str_contains($lA['body'],'B 站独有标题'),'A 站列表不含 B 站内容（防误操作）','A 站出现 B 站内容！');

echo "\n  通过 $P / 失败 $F\n";
file_put_contents('D:/Temp/h7_bfull.json',json_encode(['pass'=>$F===0,'p'=>$P,'f'=>$F],JSON_UNESCAPED_UNICODE));
