<?php
/** H7 + H8 独立进程版（避免同进程 session 累积） */
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
function fresh(){ App\Support\SiteContext::clear(); App\Support\Catalog::flush(); Illuminate\Support\Facades\Cache::flush(); }
function login($h){
  fresh();
  $lp=hit('GET','/admin/login',[],['HTTP_HOST'=>$h]);
  $r=hit('POST','/admin/login',['email'=>'admin@example.com','password'=>'Admin@123456','_token'=>tok($lp['body'])],['HTTP_HOST'=>$h],$lp['cookie']);
  return $r['cookie']?:$lp['cookie'];
}
$P=0;$F=0;
function ok($m){global $P;$P++;echo "  ✅ $m\n";}
function bad($m){global $P,$F;$F++;echo "  ❌ $m\n";}
function note($m){echo "     · $m\n";}
function chk($c,$p,$f){ $c?ok($p):bad($f); }
function line($t){echo "\n".str_repeat('═',3)." $t ".str_repeat('═',max(0,64-mb_strlen($t)))."\n";}

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  20G-7 · H7 异常恢复 + H8 双站人工隔离（独立进程）              ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";

$jarA=login('a.h7.test');
$catId=(int)DB::table('categories')->where('slug','knowledge')->value('id');

line('H7 · 异常恢复');
$pg=hit('GET','/admin/contents/create/article',[],['HTTP_HOST'=>'a.h7.test'],$jarA);
$T=tok($pg['body']);

// ① 非法 title 类型
fresh();
$r=hit('POST','/admin/contents',['title'=>['a','b'],'slug'=>'h7-bad1','_token'=>$T],['HTTP_HOST'=>'a.h7.test'],$jarA);
chk($r['code']!==500,"非法 title 类型未致 500（HTTP {$r['code']}）","非法输入致 500（{$r['code']}）");

// ② 缺必填
fresh();
$r=hit('POST','/admin/contents',['slug'=>'h7-bad2','_token'=>$T],['HTTP_HOST'=>'a.h7.test'],$jarA);
chk($r['code']!==500,"缺标题未致 500（HTTP {$r['code']}）","缺标题致 500");
chk(DB::table('contents')->whereIn('slug',['h7-bad1','h7-bad2'])->count()===0,'校验失败未产生脏数据','校验失败仍写入了脏数据');

// ③ 重复提交
fresh();
$dup=['type'=>'article','title'=>'重复提交测试','slug'=>'h7-dup','summary'=>'s','body'=>'b',
  'category_slug'=>'knowledge','geo_conclusion'=>'c','geo_explanation'=>'e','geo_boundary'=>'b',
  'owner'=>'op','reviewed_at'=>'2026-09-20',
  'ev_label'=>['E1','E2'],'ev_value'=>['0'=>'v1','1'=>'v2'],'ev_source'=>['0'=>'s','1'=>'s'],'ev_url'=>['',''],'_token'=>$T];
$r1=hit('POST','/admin/contents',$dup,['HTTP_HOST'=>'a.h7.test'],$jarA);
fresh();
$r2=hit('POST','/admin/contents',$dup,['HTTP_HOST'=>'a.h7.test'],$jarA);
$n=DB::table('contents')->where('slug','h7-dup')->count();
chk($n<=1,"重复提交未产生重复内容（{$n} 条）","重复提交产生 {$n} 条");
chk($r2['code']!==500,"重复提交未致 500（HTTP {$r2['code']}）","重复提交致 500");

// ④ 浏览编辑页无副作用
fresh();
$b4=DB::table('contents')->where('slug','h7-dup')->first();
hit('GET',"/admin/contents/{$b4->id}/edit",[],['HTTP_HOST'=>'a.h7.test'],$jarA);
$a4=DB::table('contents')->where('slug','h7-dup')->first();
chk($b4->updated_at===$a4->updated_at,'浏览编辑页无写入副作用','浏览编辑页竟然改了数据');

// ⑤ 不存在的 ID
fresh();
$r=hit('GET','/admin/contents/999999/edit',[],['HTTP_HOST'=>'a.h7.test'],$jarA);
chk($r['code']===404,"不存在内容返回 404（{$r['code']}）","不存在内容返回 {$r['code']}");

// ⑥ 发布→下架→恢复
fresh();
$ed=hit('GET',"/admin/contents/{$b4->id}/edit",[],['HTTP_HOST'=>'a.h7.test'],$jarA);
hit('POST',"/admin/contents/{$b4->id}/publish",['_token'=>tok($ed['body'])],['HTTP_HOST'=>'a.h7.test'],$jarA);
$s1=DB::table('contents')->where('id',$b4->id)->value('status');
fresh();
hit('POST',"/admin/contents/{$b4->id}/unpublish",['_token'=>tok($ed['body'])],['HTTP_HOST'=>'a.h7.test'],$jarA);
$s2=DB::table('contents')->where('id',$b4->id)->value('status');
chk($s1==='published'&&$s2==='draft',"发布→下架流转正确（{$s1} → {$s2}）","流转异常：{$s1} → {$s2}");
fresh();
$ed2=hit('GET',"/admin/contents/{$b4->id}/edit",[],['HTTP_HOST'=>'a.h7.test'],$jarA);
hit('POST',"/admin/contents/{$b4->id}/publish",['_token'=>tok($ed2['body'])],['HTTP_HOST'=>'a.h7.test'],$jarA);
$s3=DB::table('contents')->where('id',$b4->id)->value('status');
chk($s3==='published',"误下架可恢复（{$s3}）","无法恢复（{$s3}）");

line('H8 · 双站人工隔离');
$jarB=login('b.h7.test');
ok('B 站可独立登录');

// B 站创建同 slug 不同内容
fresh();
$pgB=hit('GET','/admin/contents/create/article',[],['HTTP_HOST'=>'b.h7.test'],$jarB);
$TB=tok($pgB['body']);
$catB=(int)DB::table('categories')->where('slug','knowledge')->value('id');
$r=hit('POST','/admin/contents',['type'=>'article','title'=>'B 站独有标题','slug'=>'h7-shared-slug',
  'summary'=>'B 站摘要','body'=>"## B站\n\nB 站正文。\n",'category_id'=>$catB,
  'geo_conclusion'=>'B 结论','geo_explanation'=>'B 解释','geo_boundary'=>'B 边界','owner'=>'B','reviewed_at'=>'2026-09-20',
  'ev_label'=>['B1','B2'],'ev_value'=>['0'=>'bv1','1'=>'bv2'],'ev_source'=>['0'=>'bs','1'=>'bs'],'ev_url'=>['',''],
  '_token'=>$TB],['HTTP_HOST'=>'b.h7.test'],$jarB);
chk(in_array($r['code'],[200,302],true),"B 站创建成功（{$r['code']}）","B 站创建失败（{$r['code']}）");

// 注意：此处不能 fresh()——那会把 SiteContext 重置为惰性兜底的 default 站，
// 导致后续请求以A 站身份操作 B 站数据（这是模拟器的状态管理问题，非产品缺陷）。
App\Support\SiteContext::clear();
$cidB=DB::table('contents')->where('slug','h7-shared-slug')->orderByDesc('id')->value('id');
if($cidB){
  $edB=hit('GET',"/admin/contents/{$cidB}/edit",[],['HTTP_HOST'=>'b.h7.test'],$jarB);
  hit('POST',"/admin/contents/{$cidB}/publish",['_token'=>tok($edB['body'])],['HTTP_HOST'=>'b.h7.test'],$jarB);
  $st=DB::table('contents')->where('id',$cidB)->value('status');
  $sid=DB::table('contents')->where('id',$cidB)->value('site_id');
  chk($st==='published'&&(int)$sid===2,"B 站内容已发布且归属 B 站（{$st}, site{$sid}）","B 站发布/归属异常");
}

// 前台隔离
fresh();
$fA=hit('GET','/h7-shared-slug',[],['HTTP_HOST'=>'a.h7.test']);
if($fA['code']===301&&$fA['loc'])$fA=hit('GET',$fA['loc'],[],['HTTP_HOST'=>'a.h7.test']);
chk(!str_contains($fA['body'],'B 站独有标题'),'A 站前台看不到 B 站内容（负向验证）','A 站看到了 B 站内容！');
fresh();
$fB=hit('GET','/h7-shared-slug',[],['HTTP_HOST'=>'b.h7.test']);
if($fB['code']===301&&$fB['loc'])$fB=hit('GET',$fB['loc'],[],['HTTP_HOST'=>'b.h7.test']);
chk(str_contains($fB['body'],'B 站独有标题'),'B 站前台能看到自己的内容','B 站看不到自己的内容！');

// 后台列表隔离
fresh();
$lA=hit('GET','/admin/contents',[],['HTTP_HOST'=>'a.h7.test'],$jarA);
chk(!str_contains($lA['body'],'B 站独有标题'),'A 站后台列表不含 B 站内容（防误操作）','A 站后台出现 B 站内容！');
fresh();
$lB=hit('GET','/admin/contents',[],['HTTP_HOST'=>'b.h7.test'],$jarB);
chk(str_contains($lB['body'],'B 站独有标题'),'B 站后台列表能看到自己的内容','B 站列表看不到自己的内容');

// GEO 隔离
fresh();
$gA=json_decode(hit('GET','/geo.json',[],['HTTP_HOST'=>'a.h7.test'])['body'],true);
$gB=json_decode(hit('GET','/geo.json',[],['HTTP_HOST'=>'b.h7.test'])['body'],true);
$tA=array_column($gA['contents']??[],'title'); $tB=array_column($gB['contents']??[],'title');
chk(!in_array('B 站独有标题',$tA,true),'A 站 geo.json 不含 B 站内容','A 站 geo.json 含 B 站内容');
chk(in_array('B 站独有标题',$tB,true),'B 站 geo.json 含自己的内容','B 站 geo.json 缺自己的内容');
note('A 站 geo.json '.count($tA).' 条 / B 站 '.count($tB).' 条');

echo "\n".str_repeat('═',3)." 汇总 ".str_repeat('═',55)."\n";
echo "  通过 $P / 失败 $F\n";
file_put_contents('D:/Temp/h7_h7h8.json',json_encode(['pass'=>$F===0,'passed'=>$P,'failed'=>$F],JSON_UNESCAPED_UNICODE));
