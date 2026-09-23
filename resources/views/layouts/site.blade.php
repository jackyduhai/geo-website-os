<!DOCTYPE html>
@php
  /* P-STEP 18D Light/Dark/System：默认外观与是否允许深色，均为站点外观设置（Site Scoped）。
     color_mode in light|dark|system；allow_dark=0 时前台强制浅色且不渲染切换器。 */
  $colorMode = (string) ($siteSettings['theme_color_mode'] ?? 'light');
  if (! in_array($colorMode, ['light', 'dark', 'system'], true)) { $colorMode = 'light'; }
  $allowDarkRaw = $siteSettings['theme_allow_dark'] ?? '1';
  $allowDark = ! in_array($allowDarkRaw, ['0', 0, false], true);
  $ssrMode = in_array($colorMode, ['light', 'dark'], true) ? $colorMode : '';
@endphp
<html lang="{{ \App\Support\Localization\LocaleContext::current() }}"@if($allowDark && $ssrMode !== '') data-color-scheme="{{ $ssrMode }}"@endif>
<head>
<meta charset="utf-8">
<meta name="color-scheme" content="{{ $allowDark ? 'light dark' : 'light' }}">
<script nonce="{{ $cspNonce ?? '' }}">document.documentElement.classList.add('js');</script>
{{-- P-STEP 18D：首帧前解析外观偏好（localStorage > 站点默认 > 系统偏好），写 data-color-scheme，
     避免深色模式闪屏（FOUC）。纯视觉、无业务数据；无 JS 时由 CSS prefers-color-scheme 兜底。 --}}
@if($allowDark)
<script nonce="{{ $cspNonce ?? '' }}">
(function(){
  var root = document.documentElement;
  var def = '{{ $colorMode }}';
  function sysDark(){ return window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches; }
  function resolve(m){ if(m==='dark'||m==='light') return m; return sysDark() ? 'dark' : 'light'; }
  var saved = null;
  try { saved = localStorage.getItem('gwos-color-mode'); } catch(e) {}
  if(saved!=='light' && saved!=='dark' && saved!=='system') saved = def;
  root.dataset.colorModePref = saved;
  root.setAttribute('data-color-scheme', resolve(saved));
  if(window.matchMedia && saved==='system'){
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e){
      var p=null; try{ p=localStorage.getItem('gwos-color-mode'); }catch(_){}
      if((p||def)==='system') root.setAttribute('data-color-scheme', e.matches?'dark':'light');
    });
  }
})();
</script>
@else
<script nonce="{{ $cspNonce ?? '' }}">document.documentElement.setAttribute('data-color-scheme','light');document.documentElement.dataset.colorModePref='light';</script>
@endif
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $seo['title_full'] }}</title>
<meta name="description" content="{{ $seo['description'] }}">
@if(!empty($seo['noindex']))
<meta name="robots" content="noindex, follow">
@else
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
@endif
<link rel="canonical" href="{{ $seo['canonical'] }}">
@foreach($seo['hreflang_alternates'] ?? [] as $alt)
<link rel="alternate" hreflang="{{ $alt['hreflang'] }}" href="{{ $alt['href'] }}">
@endforeach
<link rel="alternate" hreflang="x-default" href="{{ $seo['hreflang_xdefault'] }}">

{{-- SEO 头部唯一消费点：$seo 由 SeoHeadComposer 归一化（Controller SeoResult → 站点级 Resolution → 遗留设置兜底）。
     Blade 在此不做任何 SEO 计算或回读（STEP 02 单一来源）。 --}}
<meta property="og:locale" content="{{ \App\Support\Localization\LocaleContext::current() === 'en' ? 'en_US' : 'zh_CN' }}">
<meta property="og:type" content="{{ $seo['type'] }}">
<meta property="og:title" content="{{ $seo['og_title'] }}">
<meta property="og:description" content="{{ $seo['og_description'] }}">
<meta property="og:url" content="{{ $seo['canonical'] }}">
<meta property="og:site_name" content="{{ $seo['og_site_name'] }}">
<meta property="og:image" content="{{ $seo['og_image'] }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="{{ $seo['twitter_card'] }}">
<meta name="twitter:title" content="{{ $seo['og_title'] }}">
<meta name="twitter:description" content="{{ $seo['og_description'] }}">
<meta name="twitter:image" content="{{ $seo['og_image'] }}">
@if(!empty($seo['published']))
<meta property="article:published_time" content="{{ $seo['published'] }}">
@endif
@if(!empty($seo['modified']))
<meta property="article:modified_time" content="{{ $seo['modified'] }}">
@endif

<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<style>
/* ============================================================
   GEO Website OS · Design System（全站唯一一套，禁止页面另起样式）
   方法借鉴现代 SaaS 设计：8 点网格、干净中性阶、清晰字阶、
   去盒子化、品牌色克制、轻动效。默认主色为中性蓝、辅色 / CTA 为稳重绿，
   均可在后台「外观与主题」覆盖；红仅用于错误态。
   ============================================================ */
@php
  /* P-STEP 18D：:root 只消费 ThemePalette 从外观种子（品牌主色 / 辅色 / 中性 /
     圆角 / 宽度 / 密度 / 质感）派生的语义令牌，本样式表不再出现孤立品牌 Hex；
     改一个品牌基色，悬停 / 按下 / 浅底 / 反白 / 首屏深色渐变即整体协调。 */
  $themeTokens = $themeTokens ?? \App\Support\Theme\ThemePalette::resolve($siteSettings ?? []);
@endphp
:root{
  /* 品牌主色簇（主色为种子，悬停 / 按下 / 浅底 / 反白文字自动派生） */
  --brand: {{ $themeTokens['--brand'] }};
  --brand-dark: {{ $themeTokens['--brand-dark'] }};
  --brand-active: {{ $themeTokens['--brand-active'] }};
  --brand-soft: {{ $themeTokens['--brand-soft'] }};
  --brand-on: {{ $themeTokens['--brand-on'] }};
  --brand-ring: {{ $themeTokens['--brand-ring'] }};
  --brand-bright: {{ $themeTokens['--brand-bright'] }};  /* 原始明亮种子，仅供深色反白区点缀 */
  /* 辅色簇（点缀 / 标签 / focus） */
  --accent: {{ $themeTokens['--accent'] }};
  --accent-dark: {{ $themeTokens['--accent-dark'] }};
  --accent-soft: {{ $themeTokens['--accent-soft'] }};
  --accent-on: {{ $themeTokens['--accent-on'] }};
  --accent-ring: {{ $themeTokens['--accent-ring'] }};
  --accent-bright: {{ $themeTokens['--accent-bright'] }};  /* 原始明亮种子，仅供深色反白区点缀 */
  /* 主行动色（与辅色同色系派生，唯一主 CTA 色；红仅用于错误态） */
  --cta: {{ $themeTokens['--cta'] }};
  --cta-dark: {{ $themeTokens['--cta-dark'] }};
  --cta-soft: {{ $themeTokens['--cta-soft'] }};
  --cta-on: {{ $themeTokens['--cta-on'] }};
  /* 干净中性阶（承担约 70% 界面：底色 / 卡片 / 文字 / 分隔线，均由种子派生） */
  --hd-bg: {{ $themeTokens['--hd-bg'] }};  /* 吸顶导航 / 吸顶子导航毛玻璃（深色态覆盖） */
  --bg: {{ $themeTokens['--bg'] }};
  --surface: {{ $themeTokens['--surface'] }};
  --surface-2: {{ $themeTokens['--surface-2'] }};
  --surface-3: {{ $themeTokens['--surface-3'] }};
  --ink: {{ $themeTokens['--ink'] }};
  --ink-2: {{ $themeTokens['--ink-2'] }};
  --ink-muted: {{ $themeTokens['--ink-muted'] }};
  --ink-faint: {{ $themeTokens['--ink-faint'] }};
  --line: {{ $themeTokens['--line'] }};
  --line-soft: {{ $themeTokens['--line-soft'] }};
  --footer-bg: {{ $themeTokens['--footer-bg'] }};
  --footer-ink: {{ $themeTokens['--footer-ink'] }};
  --footer-dim: {{ $themeTokens['--footer-dim'] }};
  /* 语义色（info 随品牌、success 随辅色；warning/error 固定，不随品牌变化） */
  --info: {{ $themeTokens['--info'] }};
  --success: {{ $themeTokens['--success'] }};
  --warning: {{ $themeTokens['--warning'] }};
  --error: {{ $themeTokens['--error'] }};
  /* 首屏深色品牌渐变 / 光晕 / 压字遮罩（由品牌色派生，替代历史暖褐配色） */
  --hero-gradient: {{ $themeTokens['--hero-gradient'] }};
  --hero-glow: {{ $themeTokens['--hero-glow'] }};
  --hero-veil: {{ $themeTokens['--hero-veil'] }};
  --hero-kicker: {{ $themeTokens['--hero-kicker'] }};
  --hero-trust: {{ $themeTokens['--hero-trust'] }};
  --scrim: {{ $themeTokens['--scrim'] }};
  /* 圆角分级（克制：基础圆角可配，档位围绕其收敛，pill 仅 chip） */
  --radius-xs: {{ $themeTokens['--radius-xs'] }};
  --radius-sm: {{ $themeTokens['--radius-sm'] }};
  --radius: {{ $themeTokens['--radius'] }};
  --radius-lg: {{ $themeTokens['--radius-lg'] }};
  --radius-xl: {{ $themeTokens['--radius-xl'] }};
  --radius-full: {{ $themeTokens['--radius-full'] }};
  /* 容器 / 间距（4 点基准 / 8 点网格；--sec-y 随版面密度变化） */
  --container: {{ $themeTokens['--container'] }};
  --sec-y: {{ $themeTokens['--sec-y'] }};
  --sp-1: {{ $themeTokens['--sp-1'] }};--sp-2: {{ $themeTokens['--sp-2'] }};--sp-3: {{ $themeTokens['--sp-3'] }};
  --sp-4: {{ $themeTokens['--sp-4'] }};--sp-5: {{ $themeTokens['--sp-5'] }};--sp-6: {{ $themeTokens['--sp-6'] }};
  --sp-7: {{ $themeTokens['--sp-7'] }};--sp-8: {{ $themeTokens['--sp-8'] }};--sp-9: {{ $themeTokens['--sp-9'] }};
  --font: {{ $themeTokens['--font'] }};
  /* 字号阶梯（P-STEP 18D-03）：rem 基准，随用户根字号缩放；结构性标题/正文必须消费。
     display > h1 > h2 > h3 > h4 > lg > base > sm > xs > 2xs > label；button 复用 sm。
     组件辅助文字（12-15px 的 eyebrow/caption/meta）就近取 xs/2xs/label，不再自造档位。 */
  --fs-display: 3rem;        /* 48 全屏 overlay 主标题 / 超大数字 */
  --fs-h1: 2.75rem;           /* 44 Hero / 页面大标题 */
  --fs-h2: 2rem;              /* 32 区块标题 / CTA band */
  --fs-h3: 1.5rem;            /* 24 卡片特色标题 / 正文二级 */
  --fs-h4: 1.1875rem;         /* 19 正文三级 / 小标题 */
  --fs-lg: 1.125rem;          /* 18 引导段 / 正文加大 */
  --fs-base: 1rem;            /* 16 正文根 */
  --fs-sm: .9375rem;          /* 15 导航 / 按钮 / 卡片正文 */
  --fs-xs: .875rem;           /* 14 辅助说明 / 小按钮 */
  --fs-2xs: .8125rem;         /* 13 元信息 / 标签文字 */
  --fs-label: .78rem;         /* ~12.5 eyebrow / kicker / 时间 */
  --fs-button: .9375rem;       /* 按钮文字（与 sm 同档） */
  /* 动效：100/160/240ms，标准缓动 */
  --motion-fast: {{ $themeTokens['--motion-fast'] }};
  --motion-base: {{ $themeTokens['--motion-base'] }};
  --motion-slow: {{ $themeTokens['--motion-slow'] }};
  /* 阴影：默认无（用 1px 边框分层），仅悬浮 / 浮层；质感随 theme_shadow 变化 */
  --shadow-sm: {{ $themeTokens['--shadow-sm'] }};
  --shadow: {{ $themeTokens['--shadow'] }};
  --shadow-hover: {{ $themeTokens['--shadow-hover'] }};
  --shadow-overlay: {{ $themeTokens['--shadow-overlay'] }};
}
@php
  /* P-STEP 18D Light/Dark：深色仅覆盖的语义令牌子集（ThemePalette::darkOverrides）。
     主选择器命中 SSR/JS 已解析的深色；@media 内 :not([data-color-scheme]) 为无 JS 且系统深色兜底。 */
  $darkTokens = $darkTokens ?? \App\Support\Theme\ThemePalette::darkOverrides($siteSettings ?? []);
  $darkCss = \App\Support\Theme\ThemePalette::toCss($darkTokens);
@endphp
@if($allowDark)
:root[data-color-scheme="dark"]{color-scheme:dark;{!! $darkCss !!}}
@media (prefers-color-scheme:dark){:root:not([data-color-scheme]){color-scheme:dark;{!! $darkCss !!}}}
@endif
#nav-toggle{display:none}
*{box-sizing:border-box;}
html{-webkit-text-size-adjust:100%;scroll-behavior:smooth;}
body{margin:0;background:var(--bg);color:var(--ink-2);font-size:var(--fs-base);line-height:1.75;overflow-x:clip;
  font-family:var(--font);
  -webkit-font-smoothing:antialiased;}
h1,h2,h3,h4{line-height:1.3;color:var(--ink);margin:0;font-weight:700;letter-spacing:-.01em;}
p{margin:0;}
a{color:var(--brand);text-decoration:none;transition:color var(--motion-fast);}
a:hover{text-decoration:none;}
img{max-width:100%;height:auto;}
/* x-picture 统一图片组件：picture 作为块级媒体包裹层，消除 inline 间隙，内部 img 满宽 */
picture.pic{display:block;line-height:0;}
picture.pic img{display:block;max-width:100%;}
/* 固定尺寸/定比媒体容器：picture 需填满容器，内部 img 才能 height:100%+object-fit 正常裁切 */
.prod-img picture.pic,.kc-cover picture.pic,.post-thumb picture.pic,
.feat-ic picture.pic,.ps-ic picture.pic{width:100%;height:100%;}
.prose table{display:block;overflow-x:auto;max-width:100%;border-collapse:collapse;}
.prose pre{overflow-x:auto;}
/* 正文排版（内容详情，服务阅读与 GEO） */
.prose{color:var(--ink-2);max-width:72ch;}
.prose h2{font-size:var(--fs-h3);margin:36px 0 14px;font-weight:700;}
.prose h3{font-size:var(--fs-h4);margin:28px 0 10px;font-weight:600;}
.prose h4{font-size:var(--fs-base);margin:22px 0 8px;font-weight:600;}
.prose p{margin:0 0 16px;}
.prose ul,.prose ol{margin:0 0 16px;padding-left:22px;}
.prose li{margin:6px 0;}
.prose li::marker{color:var(--brand);}
.prose blockquote{margin:18px 0;padding:14px 20px;border-left:3px solid var(--brand);
  background:var(--surface-2);border-radius:0 var(--radius-sm) var(--radius-sm) 0;color:var(--ink-2);}
.prose strong{color:var(--ink);font-weight:600;}
.prose img{border-radius:var(--radius);margin:12px 0;}
.prose hr{border:0;border-top:1px solid var(--line-soft);margin:28px 0;}
/* 分页 */
nav[role=navigation]{margin-top:8px}
.pagination{display:flex;gap:6px;flex-wrap:wrap;list-style:none;padding:0;margin:0;}
.pagination .page-link{display:inline-flex;align-items:center;justify-content:center;min-width:40px;height:40px;
  padding:0 12px;border:1px solid var(--line);border-radius:var(--radius-sm);background:var(--surface);
  color:var(--ink-2);font-size:var(--fs-xs);font-weight:500;transition:all var(--motion-fast);}
.pagination .page-link:hover{border-color:var(--ink-faint);color:var(--ink);}
.pagination .active .page-link{background:var(--ink);border-color:var(--ink);color:#fff;}
.pagination .disabled .page-link{color:var(--ink-faint);background:transparent;}
.wrap{max-width:var(--container);margin:0 auto;padding:0 24px;width:100%;}
.num{font-variant-numeric:tabular-nums;}

/* ---------- 按钮体系（四级，基础形态共享，杜绝“文字加框”） ---------- */
.btn,.btn-o,.btn-ghost,.btn-text,.btn-primary,.btn-secondary{display:inline-flex;align-items:center;justify-content:center;
  gap:8px;cursor:pointer;white-space:nowrap;text-decoration:none;font-family:inherit;
  border-radius:var(--radius-sm);font-weight:600;font-size:var(--fs-button);line-height:1.2;
  padding:12px 24px;border:1px solid transparent;
  transition:background var(--motion-base),border-color var(--motion-base),color var(--motion-base),box-shadow var(--motion-base),transform var(--motion-base);}
/* 主按钮：实心强调色（每屏主行动唯一；主品牌色不做主 CTA，D02） */
.btn,.btn-primary{background:var(--cta);color:var(--cta-on);border-color:var(--cta);}
.btn:hover,.btn-primary:hover{background:var(--cta-dark);border-color:var(--cta-dark);color:var(--cta-on);transform:translateY(-1px);}
.btn:active,.btn-primary:active{background:var(--cta-dark);transform:translateY(0);}
/* 次按钮：白底中性描边、墨色文字（浅底使用） */
.btn-o,.btn-secondary{background:var(--surface);color:var(--ink);border-color:var(--line);}
.btn-o:hover,.btn-secondary:hover{background:var(--surface-2);border-color:var(--ink-faint);color:var(--ink);transform:translateY(-1px);}
/* 幽灵按钮：仅用于深色 CTA 反白区 */
.btn-ghost{background:rgba(255,255,255,.10);color:#fff;border-color:rgba(255,255,255,.66);}
.btn-ghost:hover{background:rgba(255,255,255,.20);color:#fff;}
/* 文字按钮：区块“查看全部 / 阅读全文”，hover 转红下划线（品牌强调） */
.btn-text{padding:6px 2px;color:var(--brand);font-weight:600;gap:6px;background:transparent;}
.btn-text:hover{color:var(--brand-dark);gap:10px;}
.btn-lg{padding:15px 32px;font-size:var(--fs-base);border-radius:var(--radius);}
.btn-sm{padding:8px 16px;font-size:var(--fs-xs);}

/* 统一键盘焦点环（P-STEP 18D-07）：仅键盘导航显示，鼠标点击不显示（:focus-visible）。
   表单输入框沿用 :focus 的描边+光晕，不在此列；图片轮播圆点在深图上用白环。
   浅色/深色均用品牌色（深色态品牌已提亮），满足焦点环 3:1 对比。 */
a:focus-visible, button:focus-visible, [role="button"]:focus-visible, [tabindex]:focus-visible,
.btn:focus-visible, .btn-o:focus-visible, .btn-ghost:focus-visible, .btn-text:focus-visible,
.btn-primary:focus-visible, .btn-secondary:focus-visible,
a.card:focus-visible, summary:focus-visible, .page-link:focus-visible, .pcard .go:focus-visible{
  outline:2px solid var(--brand);
  outline-offset:2px;
  border-radius:var(--radius-xs);
}
.btn .arr,.btn-o .arr,.btn-ghost .arr,.btn-text .arr,.btn-primary .arr,.btn-secondary .arr{transition:transform var(--motion-base);}
.btn:hover .arr,.btn-o:hover .arr,.btn-ghost:hover .arr,.btn-text:hover .arr,.btn-primary:hover .arr{transform:translateX(3px);}
/* 禁用 / 加载态（统一） */
.btn:disabled,.btn-primary:disabled,.btn-o:disabled,.btn-secondary:disabled,.btn-ghost:disabled,.btn-text:disabled{opacity:.45;cursor:not-allowed;transform:none;}
.btn:focus-visible,.btn-o:focus-visible,.btn-ghost:focus-visible,.btn-text:focus-visible,.btn-primary:focus-visible,.btn-secondary:focus-visible,
.lead-form input:focus-visible,.lead-form select:focus-visible,.lead-form textarea:focus-visible{outline:2px solid var(--accent);outline-offset:2px;}
.actions{display:flex;gap:14px;flex-wrap:wrap;align-items:center;}

/* ---------- 顶部导航（干净、吸顶、毛玻璃；去红绿顶条） ---------- */
.hd{background:var(--hd-bg);backdrop-filter:saturate(1.3) blur(12px);-webkit-backdrop-filter:saturate(1.3) blur(12px);
  border-bottom:1px solid var(--line-soft);position:sticky;top:0;z-index:50;}
.hd-in{display:flex;align-items:center;justify-content:space-between;gap:20px;min-height:68px;}
.logo{display:flex;align-items:center;gap:10px;font-size:20px;font-weight:700;color:var(--ink);letter-spacing:-.01em;flex-shrink:0;}
.logo img{height:40px;width:auto;display:block;}
.nav{display:flex;gap:30px;flex-wrap:wrap;align-items:center;}
.nav a{color:var(--ink-2);font-size:var(--fs-sm);font-weight:500;padding:8px 2px;border-bottom:2px solid transparent;white-space:nowrap;transition:color var(--motion-fast),border-color var(--motion-fast);}
.nav a:hover{color:var(--brand);border-bottom-color:var(--accent);}
.nav a.on{color:var(--brand);font-weight:600;border-bottom-color:var(--accent);}
.nav .sub{display:none;}
.hd-right{display:flex;align-items:center;gap:12px;flex-shrink:0}
.hd-tel{display:inline-flex;align-items:center;gap:7px;font-size:var(--fs-xs);color:var(--ink-2);font-weight:600;white-space:nowrap;
  padding:9px 14px;border-radius:var(--radius-full);background:transparent;border:1px solid var(--line);transition:all var(--motion-base);}
.hd-tel:hover{border-color:var(--brand);color:var(--brand);}
.hd-cta{display:inline-flex;}
/* 外观模式切换（浅色 / 深色 / 跟随系统；图标随 data-color-mode-pref 显隐，无 JS 默认太阳=浅色） */
.theme-mode-toggle{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;padding:0;flex-shrink:0;
  border:1px solid var(--line);border-radius:var(--radius-full);background:var(--surface);color:var(--ink-2);cursor:pointer;
  transition:color var(--motion-base),border-color var(--motion-base),background var(--motion-base);}
.theme-mode-toggle:hover{color:var(--brand);border-color:var(--brand);}
.theme-mode-toggle:focus-visible{outline:2px solid var(--brand);outline-offset:2px;}
.theme-mode-toggle svg{width:18px;height:18px;display:none;}
.theme-mode-toggle .tmt-sun{display:block;}
:root[data-color-mode-pref="dark"] .tmt-sun{display:none;}
:root[data-color-mode-pref="dark"] .tmt-moon{display:block;}
:root[data-color-mode-pref="system"] .tmt-sun{display:none;}
:root[data-color-mode-pref="system"] .tmt-sys{display:block;}
@media(max-width:768px){.theme-mode-toggle{width:38px;height:38px;}}
/* 语言切换器（P-STEP 18F） */
.locale-switch{display:inline-flex;align-items:center;gap:2px;flex-shrink:0;padding:3px;
  border:1px solid var(--line);border-radius:var(--radius-full);background:var(--surface);}
.locale-switch .ls-link,.locale-switch .ls-cur{display:inline-flex;align-items:center;justify-content:center;
  min-width:34px;height:30px;padding:0 10px;border-radius:var(--radius-full);
  font-size:var(--fs-xs);font-weight:600;line-height:1;}
.locale-switch .ls-link{color:var(--ink-2);text-decoration:none;
  transition:color var(--motion-base),background var(--motion-base);}
.locale-switch .ls-link:hover{color:var(--brand);background:var(--brand-soft);}
.locale-switch .ls-link:focus-visible{outline:2px solid var(--brand);outline-offset:1px;}
.locale-switch .ls-cur{color:var(--brand);background:var(--brand-soft);}
@media(max-width:768px){.locale-switch .ls-link,.locale-switch .ls-cur{min-width:30px;height:28px;padding:0 8px;}}

.nav-toggle{display:none;width:44px;height:44px;border:1px solid var(--line);border-radius:var(--radius-sm);background:var(--surface);
  cursor:pointer;position:relative;flex-shrink:0}
.nav-toggle span{position:absolute;left:12px;right:12px;height:2px;background:var(--ink);border-radius:2px;transition:.22s}
.nav-toggle span:nth-child(1){top:14px}.nav-toggle span:nth-child(2){top:21px}.nav-toggle span:nth-child(3){top:28px}
#nav-toggle:checked ~ .hd-in .nav-toggle span:nth-child(1){top:21px;transform:rotate(45deg)}
#nav-toggle:checked ~ .hd-in .nav-toggle span:nth-child(2){opacity:0}
#nav-toggle:checked ~ .hd-in .nav-toggle span:nth-child(3){top:21px;transform:rotate(-45deg)}

/* ---------- 首屏 Banner（图片轮播，深色渐变压字，保 GEO） ---------- */
.hero{background:var(--surface-2);}
.hero-track{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none}
.hero-track::-webkit-scrollbar{display:none}
.hero-slide{position:relative;min-width:100%;scroll-snap-align:center;}
.hero-slide img{width:100%;display:block;max-height:560px;object-fit:cover;}
.hero-overlay{position:absolute;inset:0;display:flex;flex-direction:column;justify-content:flex-end;
  background:linear-gradient(90deg,rgba(var(--scrim),.62) 0%,rgba(var(--scrim),.32) 48%,rgba(var(--scrim),0) 78%);color:#fff;padding:0 8% 7%;}
.hero-kicker{display:inline-flex;align-items:center;gap:9px;font-size:var(--fs-label);font-weight:600;letter-spacing:.14em;
  margin-bottom:14px;color:#fff;opacity:.92;text-transform:uppercase}
.hero-kicker::before{content:"";width:28px;height:2px;background:var(--brand);border-radius:2px;display:inline-block;}
.hero-overlay h1,.hero-overlay h2{font-size:var(--fs-display);line-height:1.16;margin:0 0 14px;max-width:680px;font-weight:700;letter-spacing:-.02em;text-shadow:0 2px 18px rgba(0,0,0,.22)}
.hero-overlay p{font-size:var(--fs-lg);margin:0 0 24px;max-width:560px;opacity:.94;line-height:1.7;}
.hero-actions{display:flex;gap:14px;flex-wrap:wrap}
.hero-dots{display:flex;gap:8px;justify-content:center;padding:13px 0;background:var(--surface-2)}
.hero-dots i{width:8px;height:8px;border-radius:50%;background:var(--line);transition:.2s}
.hero-dots i.on{background:var(--brand);width:22px;border-radius:5px}

/* ===== 首屏 B 模式：图片 Banner 轮播（SSR 全量、可横向滑动、圆点切换，无 JS 也可滑动） ===== */
.sr-only{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.hb{position:relative;background:rgb(var(--scrim))}
.hb-imglink{display:block;line-height:0}
.hb-track{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;-ms-overflow-style:none}
.hb-track::-webkit-scrollbar{display:none}
.hb-slide{position:relative;flex:0 0 100%;scroll-snap-align:start}
.hb-slide img{width:100%;display:block;aspect-ratio:1920/640;max-height:600px;object-fit:cover}
.hb-overlay{position:absolute;inset:0;display:flex;align-items:center;background:linear-gradient(90deg,rgba(var(--scrim),.68) 0%,rgba(var(--scrim),.38) 46%,rgba(var(--scrim),0) 78%)}
.hb-inner{width:100%;max-width:1200px;margin:0 auto;padding:0 clamp(20px,6vw,72px);max-width:720px}
.hb-title{margin:0 0 14px;color:#fff;font-size:clamp(28px,3.6vw,46px);line-height:1.16;font-weight:800;letter-spacing:-.02em;text-shadow:0 2px 18px rgba(0,0,0,.28)}
.hb-sub{margin:0 0 26px;max-width:560px;color:rgba(255,255,255,.94);font-size:clamp(15px,1.3vw,18px);line-height:1.7}
.hb-actions{display:flex;gap:14px;flex-wrap:wrap}
.hb-dots{position:absolute;left:50%;transform:translateX(-50%);bottom:18px;display:flex;gap:8px;justify-content:center;z-index:2;padding:7px 12px;border-radius:var(--radius-full);background:rgba(0,0,0,.28);backdrop-filter:blur(4px)}
.hb-dot{width:9px;height:9px;padding:0;border:0;border-radius:var(--radius-full);background:rgba(255,255,255,.55);cursor:pointer;transition:width var(--motion-base),background var(--motion-base)}
.hb-dot.on{width:24px;background:var(--surface)}
.hb-dot:focus-visible{outline:2px solid #fff;outline-offset:3px}
@media(max-width:768px){
  .hb-slide img{aspect-ratio:4/3;max-height:none}
  .hb-overlay{align-items:flex-end;background:linear-gradient(180deg,rgba(var(--scrim),.12) 0%,rgba(var(--scrim),.55) 52%,rgba(var(--scrim),.8) 100%)}
  .hb-inner{padding:0 20px 54px;max-width:none}
  .hb-actions .btn{flex:1 1 auto;justify-content:center}
  .hb-dots{bottom:14px}
}

/* ===== 首页中部横幅（装修区块 mid_banner，读 Banner·首页中部；单张通栏全宽，无两侧留白） ===== */
.midbanner{padding:0}
.mb-track{display:grid;grid-template-columns:1fr}
.mb-track.is-multi{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;gap:20px;scrollbar-width:none;-ms-overflow-style:none;
  padding:0 24px 4px;max-width:1320px;margin:0 auto}
.mb-track.is-multi::-webkit-scrollbar{display:none}
.mb-item{margin:0;position:relative;overflow:hidden;background:var(--surface-2)}
.is-multi .mb-item{flex:0 0 82%;scroll-snap-align:start;border-radius:var(--radius-lg)}
.mb-item img{display:block;width:100%;aspect-ratio:4/1;object-fit:cover}
.mb-item a{display:block;line-height:0}
.mb-cap{position:absolute;inset:auto 0 0 0;line-height:1.5;color:#fff;text-align:left;
  background:linear-gradient(180deg,rgba(var(--scrim),0) 0%,rgba(var(--scrim),.62) 100%);}
/* 压字内容对齐到 1200 内容栅格，与全站文字左缘对齐 */
.mb-cap-in{max-width:1200px;margin:0 auto;padding:30px 24px}
.mb-cap strong{display:block;font-size:clamp(18px,2vw,24px);font-weight:700;line-height:1.4}
.mb-cap span{display:block;font-size:var(--fs-xs);opacity:.92;margin-top:4px}
@media(max-width:768px){
  /* 宽幅图在更“方”的手机框里 cover 时优先保住右侧产品，左侧留白优先裁掉（压字为独立 HTML 不受影响） */
  .mb-item img{aspect-ratio:16/9;object-position:right center}
  .is-multi .mb-item{flex-basis:88%}
  .mb-cap-in{padding:20px 16px}
}

/* 分栏 Hero（新版首屏：左价值主张 / 右产品视觉，去红铺底） */
.hero-split{background:var(--bg);border-bottom:1px solid var(--line-soft);}
.hero-split .wrap{display:grid;grid-template-columns:1.02fr .98fr;gap:56px;align-items:center;padding-top:72px;padding-bottom:72px;}
.hero-kicker-line{display:inline-flex;align-items:center;gap:10px;font-size:var(--fs-label);font-weight:600;letter-spacing:.14em;
  text-transform:uppercase;color:var(--brand);margin-bottom:20px;}
.hero-kicker-line::before{content:"";width:26px;height:2px;background:var(--brand);}
.hero-title{font-size:var(--fs-h1);line-height:1.18;font-weight:800;letter-spacing:-.02em;margin:0 0 18px;color:var(--ink);}
.hero-lead{font-size:var(--fs-lg);line-height:1.75;color:var(--ink-muted);margin:0 0 28px;max-width:540px;}
.hero-trust{list-style:none;display:flex;flex-wrap:wrap;gap:10px 22px;margin:30px 0 0;padding:0;}
.hero-trust li{font-size:var(--fs-xs);color:var(--ink-2);display:inline-flex;align-items:center;gap:8px;}
.hero-trust svg{width:17px;height:17px;color:var(--hero-trust);flex-shrink:0;}

/* Hero C · 一体化主视觉（通栏）：品牌渐变铺底，右侧产品图以 mask 渐变羽化融入底色，左文案压在纯色渐变区。
   不用“整图 + 一块硬遮罩”，而是 底渐变 + 图羽化 + 柔光晕 + 文字区轻压暗 四层，过渡无硬边。 */
.hero-int{padding:0;background:var(--bg);}
.hi-panel{position:relative;overflow:hidden;isolation:isolate;display:flex;align-items:center;
  min-height:clamp(470px,56vh,600px);
  /* 品牌深色渐变：左侧沉得住白字，右侧暗调与主视觉暗部自然衔接，羽化无可见分界 */
  background:var(--hero-gradient);}
/* 暗调底图铺满整块：左侧沉入深色阴影，左缘用 mask 轻柔羽化进渐变底，
   主体自然落在右侧，整块无硬边、无可见接缝。多张时层叠交叉淡入做幻灯。 */
.hi-bgstack{position:absolute;inset:0;z-index:0;overflow:hidden;
  -webkit-mask-image:linear-gradient(96deg,rgba(0,0,0,0) 0%,rgba(0,0,0,.55) 24%,#000 44%,#000 100%);
          mask-image:linear-gradient(96deg,rgba(0,0,0,0) 0%,rgba(0,0,0,.55) 24%,#000 44%,#000 100%);}
.hi-bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:62% center;
  opacity:0;transition:opacity .9s ease,transform 6s ease;transform:scale(1.02);}
.hi-bg.on{opacity:1;transform:scale(1);}
.hi-panel:hover .hi-bg.on{transform:scale(1.03);}
/* 幻灯圆点（右下角，落在图片区、不压文字） */
.hi-dots{position:absolute;z-index:3;right:clamp(20px,5vw,72px);bottom:26px;display:flex;align-items:center;gap:9px;}
.hi-dot{width:8px;height:8px;padding:0;border:0;border-radius:var(--radius-full);background:rgba(255,255,255,.32);
  cursor:pointer;transition:width var(--motion-base),background var(--motion-base);}
.hi-dot.on{width:22px;background:var(--surface);}
.hi-dot:hover{background:rgba(255,255,255,.7);}
.hi-dot.on:hover{background:var(--surface);}
.hi-dot:focus-visible{outline:2px solid #fff;outline-offset:3px;}
/* 主视觉一层辅色光晕，增加层次（克制、不炫技） */
.hi-glow{position:absolute;inset:0;z-index:1;pointer-events:none;
  background:var(--hero-glow);}
/* 文字区轻压暗，向右渐隐，保证左文案对比、且与羽化图无缝 */
.hi-veil{position:absolute;inset:0;z-index:1;pointer-events:none;
  background:var(--hero-veil);}
.hi-in{position:relative;z-index:2;width:100%;padding-top:72px;padding-bottom:72px;}
.hi-copy{max-width:588px;color:#fff;}
/* 逐幻灯文案层：grid 同格堆叠，随背景同 index 交叉淡入（高度取最高一层，不跳动） */
.hi-copystack{display:grid;}
.hi-copy-layer{grid-area:1/1;opacity:0;transform:translateY(10px);pointer-events:none;
  transition:opacity .6s ease,transform .6s ease;}
.hi-copy-layer.on{opacity:1;transform:none;pointer-events:auto;}
.hi-kicker{display:inline-flex;align-items:center;gap:10px;font-size:var(--fs-label);font-weight:600;letter-spacing:.14em;
  text-transform:uppercase;color:var(--hero-kicker);margin-bottom:20px;}
.hi-kicker::before{content:"";width:26px;height:2px;background:var(--brand);border-radius:2px;display:inline-block;}
.hi-title{font-size:clamp(30px,3.4vw,46px);line-height:1.18;font-weight:800;letter-spacing:-.02em;margin:0 0 18px;color:#fff;
  text-shadow:0 2px 20px rgba(0,0,0,.22);}
.hi-lead{font-size:var(--fs-lg);line-height:1.78;color:rgba(255,255,255,.92);margin:0 0 30px;max-width:540px;}
.hi-actions{display:flex;flex-wrap:wrap;gap:14px;align-items:center;}
.hi-ghost{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.5);color:#fff;backdrop-filter:blur(2px);
  transition:background var(--motion-base),border-color var(--motion-base),transform var(--motion-base);}
.hi-ghost:hover{background:rgba(255,255,255,.2);border-color:#fff;color:#fff;transform:translateY(-1px);}
.hi-tel{display:inline-flex;align-items:center;font-weight:600;color:#fff;padding:0 6px;}
.hi-tel:hover{color:#fff;opacity:.82;}
.hi-trust{list-style:none;display:flex;flex-wrap:wrap;gap:10px 22px;margin:30px 0 0;padding:0;}
.hi-trust li{font-size:var(--fs-2xs);color:rgba(255,255,255,.88);display:inline-flex;align-items:center;gap:8px;}
.hi-trust svg{width:16px;height:16px;color:var(--hero-trust);flex-shrink:0;}
@media(max-width:768px){
  .hi-panel{display:block;min-height:0;}
  /* 移动端：图层在顶部通栏，向下羽化进品牌渐变；文字落在下方纯色渐变区 */
  .hi-bgstack{left:0;right:0;bottom:auto;height:46%;
    -webkit-mask-image:linear-gradient(180deg,#000 0%,#000 52%,rgba(0,0,0,.55) 74%,transparent 100%);
            mask-image:linear-gradient(180deg,#000 0%,#000 52%,rgba(0,0,0,.55) 74%,transparent 100%);}
  .hi-bg{object-position:center 28%;}
  .hi-glow{background:var(--hero-glow);}
  .hi-veil{background:linear-gradient(180deg,rgba(var(--scrim),0) 0%,rgba(var(--scrim),.32) 42%,rgba(var(--scrim),.72) 100%);}
  .hi-in{padding:0 20px 30px;}
  .hi-copy{max-width:none;padding-top:clamp(200px,52vw,270px);}
  .hi-actions{gap:10px;}
  .hi-actions .btn{flex:1 1 auto;justify-content:center;}
  .hi-tel{flex:1 1 auto;justify-content:center;}
  .hi-trust{gap:8px 16px;}
  /* 移动端圆点移到右上角，避开下方文字 */
  .hi-dots{right:16px;top:14px;bottom:auto;}
}

/* Hero 右侧：中性产品体系能力面板（A 方案，替代红色海报） */
.hero-panel{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);
  padding:22px;box-shadow:var(--shadow-hover);}
.hp-head{display:flex;align-items:center;gap:9px;padding-bottom:14px;margin-bottom:14px;border-bottom:1px solid var(--line-soft);}
.hp-dot{width:8px;height:8px;border-radius:50%;background:var(--brand);box-shadow:0 0 0 4px var(--brand-soft);}
.hp-label{font-size:var(--fs-2xs);font-weight:600;letter-spacing:.04em;color:var(--ink-2);}
.hp-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.hp-cell{display:flex;align-items:center;gap:12px;padding:13px 14px;border-radius:var(--radius-sm);
  background:var(--surface-2);border:1px solid transparent;transition:transform var(--motion-base),border-color var(--motion-base),background var(--motion-base);}
.hp-cell:hover{transform:translateY(-2px);border-color:var(--brand-soft);background:var(--surface);}
.hp-ic{flex-shrink:0;width:40px;height:40px;border-radius:10px;display:grid;place-items:center;
  background:var(--brand-soft);color:var(--brand);}
.hp-tx{display:flex;flex-direction:column;gap:2px;min-width:0;}
.hp-tx strong{font-size:var(--fs-xs);font-weight:600;color:var(--ink);}
.hp-tx em{font-style:normal;font-size:var(--fs-label);color:var(--ink-faint);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.hp-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:14px;padding-top:14px;
  border-top:1px solid var(--line-soft);}
.hp-foot span{font-size:var(--fs-label);color:var(--ink-muted);}
.hp-foot b{font-size:var(--fs-base);font-weight:700;color:var(--brand);margin-right:3px;font-variant-numeric:tabular-nums;}

/* 无 Banner 时的文字首屏（保留兜底） */
.hero-text{background:radial-gradient(1200px 480px at 88% -10%,var(--brand-soft) 0%,transparent 60%),var(--bg);
  border-bottom:1px solid var(--line-soft);}
.hero-text .wrap{padding-top:72px;padding-bottom:64px;}
.hero-text h1{font-size:var(--fs-h1);line-height:1.16;margin:0 0 16px;letter-spacing:-.02em;max-width:820px;font-weight:700}
.hero-text .sub{font-size:var(--fs-lg);font-weight:600;color:var(--brand-dark);margin:0 0 14px}
.hero-text p{font-size:var(--fs-lg);color:var(--ink-muted);max-width:760px;margin:0 0 28px;line-height:1.75}

/* ---------- 信任数据：浅色大数字行（不再红铺底） ---------- */
.stats{background:var(--surface);border-bottom:1px solid var(--line-soft);}
.stats-in{display:grid;grid-template-columns:repeat(5,1fr);}
@media(max-width:1024px){.stats-in{grid-template-columns:repeat(3,1fr);}}
.stat{padding:40px 28px;text-align:left;border-left:1px solid var(--line-soft);}
.stat:first-child{border-left:0;}
.stat-n{font-size:var(--fs-display);font-weight:700;line-height:1;letter-spacing:-.02em;color:var(--ink);
  display:flex;align-items:baseline;gap:4px;font-variant-numeric:tabular-nums;}
.stat-n i{font-style:normal;font-size:var(--fs-h4);font-weight:600;color:var(--brand);}
.stat-l{margin-top:12px;font-size:var(--fs-xs);font-weight:500;color:var(--ink-muted);letter-spacing:.01em;}
/* 居中变体（用于需要居中的场合） */
.stats.center .stat{text-align:center;}

/* ---------- 通用区块与标题 ---------- */
main{padding-bottom:0;}
.sec{padding:var(--sec-y) 0;}
.sec-tint{background:var(--surface-2);}
.sec-head{margin-bottom:40px;max-width:760px;}
.sec-head.row{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;max-width:none;flex-wrap:wrap;}
.sec-head.center{margin-left:auto;margin-right:auto;text-align:center;}
.eyebrow{display:inline-flex;align-items:center;gap:9px;font-size:var(--fs-label);font-weight:600;letter-spacing:.14em;
  color:var(--brand);text-transform:uppercase;margin-bottom:14px;}
.eyebrow::before{content:"";width:22px;height:2px;border-radius:2px;background:var(--brand);display:inline-block;}
.eyebrow.noline::before{display:none;}
.sec-head.center .eyebrow{justify-content:center;}
.sec-h{font-size:var(--fs-h2);font-weight:700;margin:0 0 14px;letter-spacing:-.02em;line-height:1.25;}
.sec-sub{color:var(--ink-muted);margin:0;font-size:var(--fs-base);line-height:1.8;}
.grid{display:grid;gap:24px;}
.g2{grid-template-columns:repeat(2,1fr);}
.g3{grid-template-columns:repeat(3,1fr);}
.g4{grid-template-columns:repeat(4,1fr);}
.grid>*{min-width:0;}

/* ---------- 卡片：浅面 + 极浅发丝线，静态无重影，可点击才悬浮 ---------- */
.card{background:var(--surface);border:1px solid var(--line-soft);border-radius:var(--radius);padding:28px;}
a.card{transition:transform var(--motion-base),box-shadow var(--motion-base),border-color var(--motion-base);}
a.card:hover{border-color:var(--line);box-shadow:var(--shadow-hover);transform:translateY(-3px);}

/* 产品卡 */
.pcard{position:relative;display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--line-soft);
  border-radius:var(--radius);padding:26px;color:inherit;
  transition:transform var(--motion-base),box-shadow var(--motion-base),border-color var(--motion-base);}
a.pcard:hover{border-color:var(--line);box-shadow:var(--shadow-hover);transform:translateY(-3px);color:inherit;}
.pcard-h{display:flex;align-items:center;gap:13px;margin-bottom:12px;}
.pcard-h .feat-ic{width:44px;height:44px;margin-bottom:0;flex-shrink:0;}
.pcard h3{margin:0;font-size:var(--fs-lg);font-weight:600;line-height:1.4;}
.pcard p{margin:0;font-size:var(--fs-xs);color:var(--ink-muted);line-height:1.75;flex:1;}
.pcard .go{margin-top:16px;font-size:var(--fs-xs);font-weight:600;color:var(--brand);display:inline-flex;align-items:center;gap:6px;}
.feat-ic{width:48px;height:48px;border-radius:var(--radius);display:inline-flex;align-items:center;justify-content:center;
  background:var(--surface-2);color:var(--ink-2);margin-bottom:16px;flex-shrink:0;}
.feat-ic.g{background:var(--accent-soft);color:var(--accent-dark);}
.feat-ic.r{background:var(--brand-soft);color:var(--brand);}
.feat-ic.ghost{background:var(--surface-2);color:var(--ink-2);}
.feat-ic svg{width:24px;height:24px;}
.feat-ic .feat-media-img{width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block;}
.ps-ic .feat-media-img{width:100%;height:100%;object-fit:cover;border-radius:inherit;}

/* 产品主次网格：首条主打横向通栏，其余规整排列 */
.pgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:24px;}
.pcard-feature{grid-column:1/-1;flex-direction:row;align-items:center;gap:28px;padding:34px 36px;
  background:linear-gradient(120deg,var(--surface) 0%,var(--surface-2) 60%,var(--surface-2) 160%);}
.pcard-feature .feat-ic{width:64px;height:64px;margin-bottom:0;flex-shrink:0;}
.pcard-feature .feat-ic svg{width:32px;height:32px;}
.pcard-feature h3{font-size:var(--fs-h3);margin-bottom:8px;}
.pcard-feature p{font-size:var(--fs-sm);margin-bottom:14px;max-width:680px;}
.pcard-feature .go{margin-top:0;}

/* 能力点：去盒子化的结构行（无边框，发丝分隔） */
.cap{display:flex;gap:16px;align-items:flex-start;background:transparent;border:0;border-radius:0;
  padding:20px 0;border-top:1px solid var(--line-soft);}
.cap:first-child{border-top:0;padding-top:0;}
.cap .feat-ic{width:46px;height:46px;margin-bottom:0;}
.cap h4{margin:2px 0 5px;font-size:var(--fs-lg);font-weight:600;}
.cap p{margin:0;font-size:var(--fs-xs);color:var(--ink-muted);line-height:1.7;}

/* 能力展示格（重音段：放大、浅面卡） */
.ws-heavy .wscard{padding:34px 24px;}
.ws-heavy .wscard .feat-ic{width:58px;height:58px;margin:0 auto 18px;}
.ws-heavy .wscard .feat-ic svg{width:28px;height:28px;}
.ws-facts{display:flex;gap:40px;margin-top:30px;padding-top:26px;border-top:1px solid var(--line);}
.wsf{display:flex;flex-direction:column;gap:4px;}
.wsf-n{font-size:34px;font-weight:700;letter-spacing:-.02em;color:var(--brand);font-variant-numeric:tabular-nums;line-height:1;}
.wsf-n small{font-size:var(--fs-base);font-weight:600;color:var(--ink-muted);margin-left:2px;}
.wsf-l{font-size:var(--fs-2xs);color:var(--ink-muted);margin-top:8px;}
.wscard{background:var(--surface);border:1px solid var(--line-soft);border-radius:var(--radius);
  padding:28px 20px;text-align:center;transition:transform var(--motion-base),box-shadow var(--motion-base),border-color var(--motion-base);}
.wscard:hover{border-color:var(--line);box-shadow:var(--shadow-hover);transform:translateY(-3px);}
.wscard .feat-ic{margin:0 auto 14px;}
.wscard h4{margin:0;font-size:var(--fs-base);font-weight:600;line-height:1.5;}
.wscard p{margin:6px 0 0;font-size:var(--fs-2xs);color:var(--ink-muted);line-height:1.6;}

/* 合作流程（描边数字 + 连线，去实心红块） */
.steps{display:grid;grid-template-columns:repeat(5,1fr);gap:20px;counter-reset:step;position:relative;}
.step{position:relative;background:var(--surface);border:1px solid var(--line-soft);border-radius:var(--radius);
  padding:26px 24px 22px;}
.step-n{width:42px;height:42px;border-radius:50%;background:var(--brand-soft);color:var(--brand);font-weight:700;font-size:var(--fs-lg);
  display:flex;align-items:center;justify-content:center;margin-bottom:16px;font-variant-numeric:tabular-nums;}
.step h4{margin:0 0 8px;font-size:var(--fs-base);font-weight:600;}
.step p{margin:0;font-size:var(--fs-2xs);color:var(--ink-muted);line-height:1.7;}
.step::after{content:"";position:absolute;top:47px;right:-16px;width:16px;height:2px;background:var(--line);z-index:1;}
.step:last-child::after{display:none;}

/* ---------- 答案块（GEO 结论前置：浅面结论面板，顶部细品牌线） ---------- */
.answer{background:var(--surface-2);border:1px solid var(--line-soft);border-top:3px solid var(--brand);
  border-radius:var(--radius);padding:28px 30px;margin:24px 0;}
.answer-t{font-size:var(--fs-label);font-weight:600;color:var(--brand);letter-spacing:.12em;margin-bottom:12px;display:flex;align-items:center;gap:8px;text-transform:uppercase;}
.answer-t::before{content:"";width:16px;height:2px;background:var(--brand);}
.answer-c{font-size:var(--fs-lg);line-height:1.8;font-weight:600;color:var(--ink);margin:0;}

/* ---------- 事实/资质：定义列表，发丝行线 ---------- */
.facts{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--line-soft);
  border:1px solid var(--line-soft);border-radius:var(--radius);overflow:hidden;margin:24px 0;}
.facts div{background:var(--surface);padding:20px 18px;transition:background var(--motion-fast);}
.facts div:hover{background:var(--surface-2);}
.facts dt{font-size:var(--fs-label);color:var(--ink-faint);margin-bottom:8px;letter-spacing:.02em;}
.facts dd{margin:0;font-size:var(--fs-sm);font-weight:600;color:var(--ink);line-height:1.6;}
.facts.auto{grid-template-columns:repeat(auto-fit,minmax(210px,1fr));}

/* ---------- 标签 ---------- */
.tag{display:inline-block;font-size:var(--fs-2xs);padding:6px 14px;border-radius:var(--radius-full);background:var(--surface-2);
  color:var(--ink-muted);margin:0 8px 8px 0;font-weight:500;border:1px solid transparent;transition:all var(--motion-fast);}
.tag:hover{background:var(--surface-3);color:var(--ink-2);}
.tag-a{background:var(--brand-soft);color:var(--brand-dark);font-weight:600;}

/* ---------- 列表 / 知识卡 ---------- */
.posts{display:grid;gap:14px;}
.post{background:var(--surface);border:1px solid var(--line-soft);border-radius:var(--radius);
  padding:22px 24px;display:block;color:inherit;transition:transform var(--motion-base),box-shadow var(--motion-base),border-color var(--motion-base);}
.post:hover{border-color:var(--line);box-shadow:var(--shadow-hover);transform:translateY(-2px);}
.post h2,.post h3{margin:0 0 8px;font-size:var(--fs-lg);font-weight:600;color:var(--ink);}
.post p{margin:0 0 6px;color:var(--ink-muted);font-size:var(--fs-xs);line-height:1.7;}
.post time{font-size:var(--fs-label);color:var(--ink-faint);}
.kgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;}
.kcard{display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--line-soft);border-radius:var(--radius);
  padding:26px;color:inherit;height:100%;transition:transform var(--motion-base),box-shadow var(--motion-base),border-color var(--motion-base);}
a.kcard:hover{border-color:var(--line);box-shadow:var(--shadow-hover);transform:translateY(-3px);color:inherit;}
.kcard .kt{font-size:var(--fs-label);color:var(--brand);font-weight:600;letter-spacing:.1em;margin-bottom:12px;text-transform:uppercase;}
.kcard h3{margin:0 0 10px;font-size:var(--fs-lg);line-height:1.5;font-weight:600;}
.kcard p{margin:0;font-size:var(--fs-xs);color:var(--ink-muted);line-height:1.75;flex:1;}
.kcard .km{margin-top:18px;font-size:var(--fs-label);color:var(--ink-faint);display:flex;justify-content:space-between;gap:10px;}
/* 知识卡封面：无封面保持纯文字卡；有封面时顶部出血铺满，16:9，不挤压文字节奏 */
.kcard .kc-cover{display:block;position:relative;aspect-ratio:16/9;margin:-26px -26px 18px;overflow:hidden;border-radius:var(--radius) var(--radius) 0 0;background:var(--surface-2);}
.kcard .kc-cover img{width:100%;height:100%;object-fit:cover;display:block;transition:transform var(--motion-slow);}
a.kcard:hover .kc-cover img{transform:scale(1.04);}
/* 新闻横向缩略图 */
.post.has-thumb{display:flex;gap:18px;align-items:center;}
.post .post-thumb{flex:0 0 132px;width:132px;aspect-ratio:16/10;border-radius:var(--radius-sm,6px);overflow:hidden;background:var(--surface-2);}
.post .post-thumb img{width:100%;height:100%;object-fit:cover;display:block;}
.post .post-body{min-width:0;flex:1;}
@media(max-width:600px){.post.has-thumb{gap:12px;align-items:flex-start;}.post .post-thumb{flex-basis:96px;width:96px;}}

/* ---------- 面包屑 / 内页页头 ---------- */
.crumb{font-size:var(--fs-2xs);color:var(--ink-faint);padding:20px 0 0;}
.crumb a{color:var(--ink-muted);}
.crumb a:hover{color:var(--brand);}
.crumb i{margin:0 8px;font-style:normal;opacity:.6;}
.page-head{background:linear-gradient(180deg,var(--surface) 0%,var(--bg) 100%);border-bottom:1px solid var(--line-soft);
  padding:48px 0 40px;margin-bottom:8px;}
.page-head h1{font-size:var(--fs-h1);line-height:1.25;margin:0 0 12px;letter-spacing:-.02em;font-weight:700;}
.page-head p{font-size:var(--fs-base);color:var(--ink-muted);max-width:820px;margin:0;line-height:1.8;}
.page-head .meta{font-size:var(--fs-2xs);color:var(--ink-faint);margin:16px 0 0;}

/* ---------- FAQ ---------- */
details.faq{background:var(--surface);border:1px solid var(--line-soft);border-radius:var(--radius);margin-bottom:12px;
  padding:0 22px;transition:border-color var(--motion-base),box-shadow var(--motion-base);}
details.faq[open]{border-color:var(--line);box-shadow:var(--shadow-sm);}
details.faq summary{font-weight:600;font-size:var(--fs-base);list-style:none;cursor:pointer;padding:19px 26px 19px 0;position:relative;color:var(--ink);}
details.faq summary::-webkit-details-marker{display:none;}
details.faq summary::after{content:"+";position:absolute;right:2px;top:50%;transform:translateY(-50%);
  font-size:22px;font-weight:300;color:var(--brand);transition:transform var(--motion-base);}
details.faq[open] summary::after{transform:translateY(-50%) rotate(45deg);}
details.faq .faq-a{margin:0 0 20px;font-size:var(--fs-sm);color:var(--ink-muted);line-height:1.85;}

/* ---------- 表单（全站唯一） ---------- */
.lead-form{background:var(--surface);border:1px solid var(--line-soft);border-radius:var(--radius-lg);
  padding:34px 36px;max-width:860px;}
.lead-form .lf-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px 20px;}
.lead-form .lf-full{grid-column:1/-1;}
.lead-form label{display:block;font-size:var(--fs-xs);font-weight:600;margin-bottom:8px;color:var(--ink);}
.lead-form label .req{color:var(--brand);}
.lead-form input,.lead-form select,.lead-form textarea{width:100%;padding:12px 14px;border:1px solid var(--line);
  border-radius:var(--radius-sm);font-size:var(--fs-sm);font-family:inherit;background:var(--surface);color:var(--ink);box-sizing:border-box;
  transition:border-color var(--motion-fast),box-shadow var(--motion-fast);}
.lead-form input:focus,.lead-form select:focus,.lead-form textarea:focus{outline:none;border-color:var(--cta);
  box-shadow:0 0 0 3px var(--accent-ring);}
.lead-form textarea{min-height:120px;resize:vertical;}
.lead-form .hp{position:absolute;left:-9999px;height:0;overflow:hidden;}
.lead-form .lf-err{color:var(--brand);font-size:var(--fs-2xs);margin:6px 0 0;}
.lead-ok{background:var(--accent-soft);border:1px solid var(--accent-soft);color:var(--accent-dark);border-radius:var(--radius);
  padding:18px 22px;font-weight:600;max-width:860px;}

/* ---------- CTA 色带（墨黑反白收口，主按钮绿在其上突出） ---------- */
.cta-band{position:relative;background:linear-gradient(120deg,var(--footer-bg),rgba(255,255,255,.05) 60%,var(--footer-bg));
  color:#fff;border-radius:var(--radius-lg);padding:52px 52px;overflow:hidden;}
.cta-band::after{content:"";position:absolute;right:-90px;bottom:-140px;width:360px;height:360px;border-radius:50%;
  background:radial-gradient(circle,rgba(255,255,255,.08),transparent 70%);}
.cta-band h2{color:#fff;font-size:var(--fs-h2);margin:0 0 12px;font-weight:700;letter-spacing:-.01em;}
.cta-band p{margin:0 0 24px;opacity:.92;font-size:var(--fs-base);max-width:580px;line-height:1.75;}
.cta-band .cta-phone{font-size:34px;font-weight:700;letter-spacing:.01em;font-variant-numeric:tabular-nums;}
.cta-band .cta-row{display:flex;gap:14px;flex-wrap:wrap;align-items:center;position:relative;z-index:1;}

/* ---------- 页脚 ---------- */
.ft{background:var(--footer-bg);color:var(--footer-ink);padding:56px 0 28px;}
.ft a{color:var(--footer-ink);}
.ft a:hover{color:#fff;}
.ft-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1.2fr;gap:36px;}
.ft .ft-brand img{height:40px;margin-bottom:16px;}
.ft h4{color:#fff;font-size:var(--fs-sm);margin:0 0 16px;font-weight:600;}
.ft ul{list-style:none;padding:0;margin:0;}
.ft li{margin-bottom:11px;font-size:var(--fs-xs);color:var(--footer-dim);line-height:1.6;}
.ft .ft-desc{font-size:var(--fs-2xs);line-height:1.85;color:var(--footer-dim);margin:0;max-width:330px;}
.ft-btm{border-top:1px solid rgba(255,255,255,.08);margin-top:40px;padding-top:22px;
  font-size:var(--fs-2xs);color:var(--footer-dim);display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;}

/* ============================================================
   v0.6 组件：场景卡 SceneCard / 参数表 ParamTable / Hero 参数卡
   / 场景详情布局 / 合作方式。全部走 Design Token，禁止另起色值。
   ============================================================ */
/* 场景自选卡（3px 绿顶条，hover 上移+轻阴影+揭示参数） */
.scene-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;}
.scene-card{position:relative;display:flex;flex-direction:column;background:var(--surface);
  border:1px solid var(--line);border-radius:var(--radius);padding:28px 24px 22px;color:inherit;overflow:hidden;
  transition:transform var(--motion-base),box-shadow var(--motion-base),border-color var(--motion-base);}
.scene-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-hover);border-color:var(--line);color:inherit;}
.sc-top{position:absolute;top:0;left:0;right:0;height:3px;background:var(--accent);}
.sc-img{display:block;border-radius:var(--radius-sm,6px);overflow:hidden;margin:2px 0 4px;}
.sc-img img{width:100%;height:128px;object-fit:cover;display:block;}
.sc-ic{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:10px;
  background:var(--accent-soft);color:var(--accent-dark);margin:2px 0 4px;}
.sc-ic svg{width:22px;height:22px;}
.sc-name{font-size:var(--fs-h4);font-weight:700;color:var(--ink);margin:8px 0 10px;}
.sc-intro{font-size:var(--fs-xs);color:var(--ink-muted);line-height:1.7;margin:0 0 14px;}
.sc-combo{display:flex;flex-wrap:wrap;gap:6px;}
.sc-combo em{font-style:normal;font-size:var(--fs-label);padding:3px 10px;border-radius:var(--radius-full);
  background:var(--surface-2);color:var(--ink-2);}
.sc-reveal{margin-top:14px;padding-top:14px;border-top:1px dashed var(--line);display:flex;flex-direction:column;gap:8px;}
.scp{display:flex;flex-direction:column;gap:3px;max-height:0;opacity:0;overflow:hidden;
  transition:max-height var(--motion-slow),opacity var(--motion-base);}
.scene-card:hover .scp{max-height:140px;opacity:1;}
.scp b{font-size:var(--fs-2xs);color:var(--ink);font-weight:600;}
.scp i{font-style:normal;font-size:var(--fs-label);color:var(--ink-muted);line-height:1.6;}
.sc-go{margin-top:2px;font-size:var(--fs-2xs);font-weight:600;color:var(--brand);display:inline-flex;gap:6px;align-items:center;}
.scene-card:hover .sc-go .arr{transform:translateX(3px);}
.sc-go .arr{transition:transform var(--motion-base);}

/* 参数级交付表 ParamTable（原生 table，仅水平分隔线，数字 tabular） */
.param-table{width:100%;border-collapse:collapse;font-size:var(--fs-xs);background:var(--surface);
  border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;}
.param-table thead th{background:var(--surface-2);font-size:var(--fs-label);letter-spacing:.04em;color:var(--ink-muted);
  font-weight:600;text-align:left;padding:12px 14px;border-bottom:1px solid var(--line);}
.param-table tbody th,.param-table td{text-align:left;padding:13px 14px;border-bottom:1px solid var(--line-soft);
  vertical-align:top;line-height:1.6;}
.param-table tbody tr:last-child th,.param-table tbody tr:last-child td{border-bottom:0;}
.param-table tbody th{font-weight:600;color:var(--ink);width:32%;}
.param-table td{color:var(--ink-2);}
.param-table td.num{font-variant-numeric:tabular-nums;}
.tbl-note{font-size:var(--fs-label);color:var(--ink-faint);margin:10px 2px 0;line-height:1.6;}

/* Hero 右侧参数卡 ParamCard */
.param-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);overflow:hidden;
  box-shadow:var(--shadow-hover);}
.pc-head{padding:16px 20px;background:var(--surface-2);border-bottom:1px solid var(--line-soft);
  display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;}
.pc-head strong{font-size:var(--fs-xs);color:var(--ink);}
.pc-head span{font-size:var(--fs-label);color:var(--ink-faint);}
.pc-body{padding:6px 0;}
.pc-row{display:grid;grid-template-columns:auto 1fr;gap:4px 16px;padding:12px 20px;border-bottom:1px solid var(--line-soft);}
.pc-row:last-child{border-bottom:0;}
.pc-row dt{color:var(--ink-faint);font-size:var(--fs-2xs);margin:0;}
.pc-row dd{margin:0;color:var(--ink);font-weight:600;font-size:var(--fs-xs);font-variant-numeric:tabular-nums;line-height:1.5;}
.pc-foot{padding:14px 20px;border-top:1px solid var(--line-soft);display:flex;flex-direction:column;gap:10px;}
.pc-lines{display:flex;gap:8px;flex-wrap:wrap;}
.pc-lines em{font-style:normal;font-size:var(--fs-label);padding:3px 10px;border-radius:var(--radius-full);
  background:var(--accent-soft);color:var(--accent-dark);}
.pc-foot a{font-size:var(--fs-2xs);font-weight:600;color:var(--brand);display:inline-flex;gap:6px;align-items:center;}

/* 场景详情布局 */
.scn-layout{display:grid;grid-template-columns:1.12fr .88fr;gap:52px;align-items:start;}
.scn-side{position:sticky;top:88px;}
.blk-h{font-size:var(--fs-h4);font-weight:700;margin:28px 0 12px;color:var(--ink);}
.scn-main .blk-h:first-child{margin-top:0;}
.blk-p{font-size:var(--fs-sm);color:var(--ink-2);line-height:1.85;margin:0;}
.blk-list{margin:0;padding-left:20px;color:var(--ink-2);font-size:var(--fs-sm);line-height:2;}
.blk-list li::marker{color:var(--accent);}
.scn-combo{display:flex;flex-wrap:wrap;gap:8px;}
.scn-note{margin-top:14px;font-size:var(--fs-2xs);color:var(--ink-muted);background:var(--surface-2);
  border-radius:var(--radius);padding:14px 16px;line-height:1.7;margin-bottom:0;}
.scn-side-note p{margin:0;font-size:var(--fs-xs);color:var(--ink-muted);line-height:1.8;}
.scn-pager{display:flex;justify-content:space-between;gap:16px;align-items:stretch;}
.scn-page{display:flex;flex-direction:column;gap:4px;flex:1;max-width:320px;border:1px solid var(--line);
  border-radius:var(--radius);padding:15px 20px;color:inherit;font-size:var(--fs-sm);font-weight:600;
  transition:border-color var(--motion-base),box-shadow var(--motion-base);}
.scn-page span{font-size:var(--fs-label);font-weight:400;color:var(--ink-faint);}
.scn-page.next{text-align:right;}
.scn-page.all{flex:0 0 auto;justify-content:center;color:var(--brand);}
a.scn-page:hover{border-color:var(--ink-faint);box-shadow:var(--shadow-hover);color:inherit;}

/* 合作方式 */
.coop-card h3{font-size:var(--fs-h4);margin:2px 0 10px;}
.coop-card>p{font-size:var(--fs-xs);color:var(--ink-muted);line-height:1.75;margin:0 0 14px;}
.coop-points{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:9px;}
.coop-points li{position:relative;padding-left:24px;font-size:var(--fs-xs);color:var(--ink-2);line-height:1.6;}
.coop-points li::before{content:"\2713";position:absolute;left:0;top:0;color:var(--accent-dark);font-weight:700;}
.coop-steps .step-n{background:var(--accent-soft);color:var(--accent-dark);}
.coop-two{display:grid;grid-template-columns:1.35fr .85fr;gap:48px;align-items:start;}
.coop-cta{position:sticky;top:88px;}
.coop-cta h3{font-size:var(--fs-h4);margin:0 0 10px;}
.coop-cta>p{font-size:var(--fs-xs);color:var(--ink-muted);line-height:1.75;margin:0 0 20px;}

/* 首页：参数级交付双栏 + 合作三列 + 首页FAQ */
.params-layout{display:grid;grid-template-columns:.85fr 1.15fr;gap:48px;align-items:center;}
.params-side .sec-sub{margin-bottom:0;}
.params-points{list-style:none;margin:22px 0 0;padding:0;display:flex;flex-direction:column;gap:14px;}
.params-points li{position:relative;padding-left:30px;font-size:var(--fs-sm);color:var(--ink-2);line-height:1.7;}
.params-points li::before{content:"\2713";position:absolute;left:0;top:1px;width:20px;height:20px;border-radius:50%;
  background:var(--accent-soft);color:var(--accent-dark);font-size:var(--fs-label);font-weight:700;display:grid;place-items:center;}
.home-coop{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;}
.home-coop .coop-card{padding:28px;}
.home-faq{max-width:860px;margin:0 auto;}

/* ---------- 滚动出现（渐进增强：仅在 JS 可用时初始隐藏，无 JS / 异常时内容照常可见） ---------- */
html.js .reveal{opacity:0;transform:translateY(10px);transition:opacity var(--motion-slow),transform var(--motion-slow);}
html.js .reveal.in{opacity:1;transform:none;}

/* ---------- 响应式：1024 / 900 / 768 / 600 / 380 ---------- */
@media(max-width:1024px){
  .g4{grid-template-columns:repeat(2,1fr);}
  .pgrid{grid-template-columns:repeat(2,1fr);}
  .ws-layout{grid-template-columns:1fr!important;gap:40px!important;}
  .steps{grid-template-columns:repeat(3,1fr);}
  .step::after{display:none;}
  .kgrid{grid-template-columns:repeat(2,1fr);}
  .hero-split .wrap{grid-template-columns:1fr;gap:40px;padding-top:56px;padding-bottom:56px;}
  .hero-title{font-size:42px;}
  .hero-panel{max-width:560px;}
  .ps-grid{grid-template-columns:1fr;}
  .scene-grid{grid-template-columns:repeat(2,1fr);}
  .scn-layout,.coop-two,.params-layout{grid-template-columns:1fr;gap:36px;}
  .scn-side,.coop-cta{position:static;}
}
@media(max-width:900px){
  .facts,.facts.auto{grid-template-columns:repeat(2,1fr);}
  .g3{grid-template-columns:repeat(2,1fr);}
  .ft-grid{grid-template-columns:repeat(2,1fr);gap:28px;}
  .hero-overlay h1,.hero-overlay h2{font-size:34px}
  .hero-slide img{max-height:440px}
}
@media(max-width:768px){
  .nav-toggle{display:block;width:38px;height:38px;flex-shrink:0}
  /* 移动端顶栏直接保留两个动作入口（不再藏进汉堡菜单）：
     电话=仅图标的圆形描边按钮（完整号码在 aria-label，点按即拨号），CTA=紧凑绿按钮 */
  .hd-right{gap:8px}
  .hd-right .hd-tel{display:inline-flex;width:38px;height:38px;padding:0;gap:0;justify-content:center;
    border-radius:var(--radius-full);flex-shrink:0}
  .hd-tel-num{display:none}
  .hd-right .hd-cta{padding:8px 12px;font-size:13px}
  /* 移动端 header 用不透明白底、去掉毛玻璃：backdrop-filter 会成为 fixed 后代的包含块，
     且其合成层会导致内部 overflow 滚动容器在部分移动 WebView 滚动时局部不重绘（白块）。
     桌面端仍保留毛玻璃。 */
  .hd{background:var(--surface);backdrop-filter:none;-webkit-backdrop-filter:none;}
  /* 抽屉面板：相对视口 fixed，钉在吸顶 header 下方并占满剩余视口，菜单超高时在面板内
     独立滚动（含 iOS 惯性、边界收敛）。背景滚动由 JS 拦截抽屉外 touchmove/wheel。 */
  .hd-in nav{position:fixed;top:56px;left:0;right:0;bottom:0;
    background:var(--surface);box-shadow:var(--shadow-overlay);z-index:49;display:none;
    overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;
    transform:translateZ(0);}
  #nav-toggle:checked ~ .hd-in nav{display:block}
  .nav{position:static;flex-direction:column;align-items:stretch;gap:0;
    border-bottom:1px solid var(--line);padding:8px 0;display:flex;box-shadow:none}
  .nav a{padding:15px 24px;border-bottom:1px solid var(--line-soft);font-size:16px;white-space:normal;font-weight:500}
  .nav a:last-child{border-bottom:0}
  .nav a:hover,.nav a.on{background:var(--brand-soft);}
  .g2,.g3,.g4,.ft-grid,.facts,.facts.auto{grid-template-columns:1fr;}
  .stats-in{grid-template-columns:repeat(2,1fr);}
  .stat:nth-child(3){border-left:0;}
  .stat{padding:30px 18px;}
  .stat:nth-child(n+3){border-top:1px solid var(--line-soft);}
  .kgrid{grid-template-columns:1fr;}
  .sec{padding:64px 0;}
  .cta-band{padding:40px 30px;}
  .scene-grid,.home-coop{grid-template-columns:1fr;}
  /* 移动端场景卡参数默认展开（无 hover） */
  .scene-card .scp{max-height:none;opacity:1;}
  .scn-pager{flex-direction:column;}
  .scn-page{max-width:none;}
  .scn-page.next{text-align:left;}
}
@media(max-width:600px){
  body{font-size:15px;}
  .wrap{padding:0 16px;}
  .hd-in{min-height:62px;gap:12px}
  .logo img{height:34px}
  .g2,.g3,.g4,.ft-grid,.facts,.facts.auto{grid-template-columns:1fr;}
  .steps{grid-template-columns:1fr;}
  .pgrid{grid-template-columns:1fr;}
  .pcard-feature{flex-direction:column;align-items:flex-start;gap:18px;padding:26px 24px;}
  .pcard-feature .feat-ic{width:52px;height:52px;}
  .pcard-feature .feat-ic svg{width:26px;height:26px;}
  .pcard-feature h3{font-size:19px;}
  .sec{padding:48px 0;}
  .sec-h{font-size:24px;}
  .sec-head{margin-bottom:28px;}
  .hero-split .wrap{padding-top:40px;padding-bottom:40px;gap:32px;}
  .hero-title{font-size:32px;}
  .hero-lead{font-size:16px;}
  .hp-grid{grid-template-columns:1fr;}
  .hp-foot{flex-wrap:wrap;gap:10px 18px;}
  .ws-facts{gap:26px;flex-wrap:wrap;}
  .wsf-n{font-size:28px;}
  .ws-heavy .wscard{padding:26px 18px;}
  .hero-text .wrap{padding-top:44px;padding-bottom:40px;}
  .hero-text h1{font-size:30px;}
  .answer{padding:22px 20px;}
  .answer-c{font-size:16.5px;}
  .hero-slide img{max-height:320px}
  .hero-overlay{background:linear-gradient(180deg,rgba(var(--scrim),.32),rgba(var(--scrim),.66));justify-content:flex-end;padding:22px 18px 26px}
  .hero-overlay h1,.hero-overlay h2{font-size:26px;max-width:100%}
  .hero-overlay p{font-size:14.5px;margin-bottom:16px}
  .hero-overlay .btn,.hero-overlay .btn-ghost{padding:10px 20px;font-size:14px}
  .stat-n{font-size:38px;}
  .cta-band{padding:32px 24px;border-radius:var(--radius);}
  .cta-band h2{font-size:22px;}
  .cta-band .cta-phone{font-size:26px;}
  .lead-form{padding:24px 20px;}
  .lead-form .lf-grid{grid-template-columns:1fr;}
  .page-head{padding:30px 0 26px;}
  .page-head h1{font-size:26px;}
  .ft-btm{flex-direction:column;gap:6px}
  /* 参数表移动端转键值卡，避免横向滚动 */
  .param-table thead{display:none;}
  .param-table,.param-table tbody{display:block;}
  .param-table tr{display:block;border:1px solid var(--line);border-radius:var(--radius);margin-bottom:10px;}
  .param-table tbody th,.param-table td{display:flex;justify-content:space-between;gap:18px;text-align:right;
    border-bottom:1px solid var(--line-soft);}
  .param-table tbody tr:last-child th,.param-table tbody tr:last-child td{border-bottom:0;}
  .param-table th::before,.param-table td::before{content:attr(data-label);color:var(--ink-faint);
    font-weight:500;text-align:left;flex-shrink:0;}
  /* 移动端首屏主 CTA 全宽、可见 */
  .hero-split .actions{flex-direction:column;align-items:stretch;}
  .hero-split .actions .btn{width:100%;}
  .blk-h{font-size:17px;}
}
@media(max-width:380px){
  .stats-in{grid-template-columns:1fr;}
  .stat{border-left:0!important;border-top:1px solid var(--line-soft);}
  .stat:first-child{border-top:0;}
}
@media(prefers-reduced-motion:reduce){
  *{animation:none!important;transition:none!important;scroll-behavior:auto!important;}
  html.js .reveal{opacity:1!important;transform:none!important;}
}

/* ============================================================
   v0.7 组件层：导航下拉 / SubNav / ol 面包屑 / BottomCTA / 反白 /
   多容器 / ProductCard / 时间轴 / 联系栅格 / 五列页脚。全部走 Token。
   ============================================================ */
/* 多容器宽度（1200 默认 / 1320 宽 / 960 窄 / 720 阅读行宽） */
.wrap-wide{max-width:1320px;margin:0 auto;padding:0 24px;width:100%;}
.wrap-narrow{max-width:960px;margin:0 auto;padding:0 24px;width:100%;}
.text-measure{max-width:720px;}

/* 导航高度：桌面 80，滚动收缩 64（M9），移动 56 */
.hd-in{min-height:80px;transition:min-height var(--motion-slow);}
.hd.scrolled{box-shadow:var(--shadow-sm);}
.hd.scrolled .hd-in{min-height:64px;}
/* 一级导航改为列表 + 下拉 */
.nav{gap:6px;flex-wrap:nowrap;}
.nav>li{position:relative;list-style:none;}
.nav>li>a{display:inline-flex;align-items:center;gap:5px;padding:10px 12px;}
.nav .caret{width:11px;height:11px;opacity:.55;transition:transform var(--motion-base);}
.nav>li:hover .caret,.nav>li:focus-within .caret,.nav>li.open .caret{transform:rotate(180deg);}
.nav-panel{position:absolute;top:calc(100% + 6px);left:50%;transform:translateX(-50%) translateY(8px);min-width:240px;
  background:var(--surface);border:1px solid var(--line-soft);border-radius:12px;box-shadow:var(--shadow-overlay);padding:8px;
  display:flex;flex-direction:column;opacity:0;visibility:hidden;transition:opacity var(--motion-base),transform var(--motion-base),visibility var(--motion-base);z-index:60;}
/* 无 JS 兜底：hover/聚焦即开；有 JS 时桌面端另由 .open 接管（见下方媒体查询），实现延时关闭、消除抖动 */
.nav>li:hover .nav-panel,.nav>li:focus-within .nav-panel,.nav>li.open .nav-panel{opacity:1;visibility:visible;transform:translateX(-50%) translateY(0);}
/* hover 桥接带：填补一级项与弹出层之间的物理间隙（透明、不可见；移动端已 display:none） */
.nav>li::after{content:"";position:absolute;top:100%;left:-8px;right:-8px;height:16px;}
/* JS 接管桌面端下拉：鼠标 :hover 不再瞬时开关，统一由 .open 控制并带 120ms 关闭延时，
   斜向移动 / 在紧贴导航的首屏大图上移动都不会反复闪烁；键盘聚焦仍即时开。移动端走静态手风琴，不受影响。 */
@media(min-width:769px){
  html.js .nav>li .nav-panel{opacity:0;visibility:hidden;transform:translateX(-50%) translateY(8px);}
  html.js .nav>li.open .nav-panel,html.js .nav>li:focus-within .nav-panel{opacity:1;visibility:visible;transform:translateX(-50%) translateY(0);}
}
.nav-panel a{display:block;padding:11px 14px;border:0;border-radius:8px;font-size:14.5px;font-weight:500;
  color:var(--ink-2);white-space:normal;line-height:1.5;}
.nav-panel a:hover{background:var(--surface-2);color:var(--brand);}

/* 面包屑：nav>ol>li 语义结构 */
.crumb ol{list-style:none;display:flex;flex-wrap:wrap;align-items:center;margin:0;padding:0;}
.crumb li{display:inline-flex;align-items:center;}
.crumb li[aria-current]{color:var(--ink);font-weight:500;}
.crumb .sep{margin:0 8px;opacity:.55;}

/* SubNav（产品 / 知识 / 关于三类页，吸顶 48，移动横向滚动——全站唯一允许横滚处） */
.subnav{position:sticky;top:64px;z-index:40;background:var(--hd-bg);
  -webkit-backdrop-filter:saturate(1.3) blur(10px);backdrop-filter:saturate(1.3) blur(10px);
  border-bottom:1px solid var(--line-soft);}
.subnav-in{display:flex;gap:40px;overflow-x:auto;scrollbar-width:none;height:48px;align-items:center;}
.subnav-in::-webkit-scrollbar{display:none;}
.subnav a{font-size:15px;font-weight:500;color:var(--ink-muted);white-space:nowrap;padding:13px 2px;
  border-bottom:2px solid transparent;transition:color var(--motion-fast),border-color var(--motion-fast);}
.subnav a.on{color:var(--brand);font-weight:600;border-bottom-color:var(--accent);}

/* 统一收口 BottomCTA（浅灰，非反白；联系页例外不输出） */
.bcta{background:var(--surface-2);padding:64px 0;}
.bcta-in{max-width:960px;margin:0 auto;text-align:center;}
.bcta h2{font-size:30px;line-height:1.3;margin:0 0 12px;}
.bcta .bcta-d{color:var(--ink-muted);margin:0 auto 26px;font-size:16px;line-height:1.8;max-width:560px;}
.bcta .actions{justify-content:center;}
.bcta-phone{margin-top:22px;font-size:14px;color:var(--ink-muted);}
.bcta-phone a{font-size:22px;font-weight:700;color:var(--ink);font-variant-numeric:tabular-nums;letter-spacing:.01em;margin-left:8px;}

/* 反白区块（全站≤3处：S04参数、产品详情参数、页脚）；次级文字 var(--footer-dim)，强调用绿 */
.is-inverse{background:var(--footer-bg);color:var(--footer-ink);}
.is-inverse h2,.is-inverse h3,.is-inverse h4{color:#fff;}
.is-inverse .muted,.is-inverse .sec-sub{color:var(--footer-dim);}
.is-inverse .accent{color:var(--accent-bright);}
.is-inverse .eyebrow{color:var(--accent-bright);}
.is-inverse .params-points li{color:var(--footer-ink);}
.is-inverse .params-points strong{color:#fff;}
.is-inverse .params-side .btn-primary{background:var(--cta);color:var(--cta-on);}
.is-inverse .param-table thead th{background:rgba(255,255,255,.06);}
.is-inverse .param-table{background:transparent;border-color:rgba(255,255,255,.15);}
.is-inverse .param-table thead th{background:rgba(255,255,255,.06);color:var(--footer-dim);border-bottom-color:rgba(255,255,255,.15);}
.is-inverse .param-table tbody th{color:#fff;border-bottom-color:rgba(255,255,255,.10);}
.is-inverse .param-table td{color:var(--footer-ink);border-bottom-color:rgba(255,255,255,.10);}
.is-inverse .param-table tbody tr:last-child th,.is-inverse .param-table tbody tr:last-child td{border-bottom:0;}
.is-inverse .tbl-note{color:var(--footer-dim);}

/* ProductCard：无重框、3px 绿顶、1:1 图、名 16/24、一句话 13/20 最多 2 行 */
.prod-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;}
.prod-card{position:relative;display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--line-soft);
  border-radius:var(--radius);overflow:hidden;color:inherit;
  transition:transform var(--motion-base),box-shadow var(--motion-base),border-color var(--motion-base);}
.prod-card::before{content:"";display:block;height:3px;background:var(--accent);}
a.prod-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-hover);border-color:var(--line);color:inherit;}
.prod-img{aspect-ratio:1/1;background:var(--surface-2);display:grid;place-items:center;overflow:hidden;position:relative;}
.prod-img img{width:100%;height:100%;object-fit:cover;transition:transform var(--motion-slow);}
a.prod-card:hover .prod-img img{transform:scale(1.02);}
.prod-img .ph-ic{width:46px;height:46px;color:var(--ink-faint);}
/* 无实拍图时：用克制的品牌字形徽章替代大块灰底空框，避免“破图/未完成”观感 */
.prod-img.is-empty{background:linear-gradient(155deg,var(--surface) 0%,var(--surface-2) 100%);}
.prod-img.is-empty .ph-chip{width:74px;height:74px;border-radius:var(--radius-full);background:var(--surface);border:1px solid var(--line-soft);box-shadow:var(--shadow-sm);display:grid;place-items:center;transition:transform var(--motion-base);}
.prod-img.is-empty .ph-chip .ph-ic{width:34px;height:34px;color:var(--accent);}
a.prod-card:hover .prod-img.is-empty .ph-chip{transform:scale(1.06);}
.prod-bd{padding:16px 18px 18px;display:flex;flex-direction:column;gap:8px;flex:1;}
.prod-bd h3{font-size:16px;line-height:24px;font-weight:600;margin:0;}
.prod-bd p{font-size:13px;line-height:20px;color:var(--ink-muted);margin:0;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.prod-go{margin-top:auto;padding-top:6px;font-size:13.5px;font-weight:600;color:var(--brand);
  display:inline-flex;gap:6px;align-items:center;}
.prod-tag{font-size:12px;color:var(--ink-faint);letter-spacing:.04em;}

/* S03 产品体系：feature 卡各占半行，普通卡各占 1/3（数量由数据决定） */
.pgrid{grid-template-columns:repeat(6,1fr);gap:20px;}
.pgrid .pcard-feature{grid-column:span 3;flex-direction:column;align-items:flex-start;gap:0;padding:28px;}
.pgrid .pcard-feature .feat-ic{width:56px;height:56px;margin-bottom:18px;}
.pgrid .pcard-feature .feat-ic svg{width:28px;height:28px;}
.pgrid .pcard:not(.pcard-feature){grid-column:span 2;}
.pcard h3{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;}
.pcard-count{font-size:12.5px;font-weight:500;color:var(--ink-faint);}
@media(max-width:1024px){
  .pgrid .pcard-feature{grid-column:span 3;}
  .pgrid .pcard:not(.pcard-feature){grid-column:span 2;}
}
@media(max-width:768px){
  .pgrid{grid-template-columns:repeat(2,1fr);}
  .pgrid .pcard-feature,.pgrid .pcard:not(.pcard-feature){grid-column:span 2;}
  .pgrid .pcard-feature{flex-direction:row;align-items:center;gap:18px;padding:22px;}
  .pgrid .pcard-feature .feat-ic{width:46px;height:46px;margin:0;flex-shrink:0;}
}

/* S07 匿名合作剪影 */
.case-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;}
.case-card{background:var(--surface);border:1px solid var(--line-soft);border-radius:var(--radius-lg);padding:26px;
  transition:transform var(--motion-base),box-shadow var(--motion-base),border-color var(--motion-base);}
.case-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-hover);border-color:var(--line);}
.case-img{border-radius:var(--radius);overflow:hidden;margin:-2px 0 16px;}
.case-img img{width:100%;height:150px;object-fit:cover;display:block;}
.case-meta{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;}
.case-type{font-size:17px;font-weight:700;color:var(--ink);}
.case-region{font-size:13px;color:var(--ink-faint);white-space:nowrap;}
.case-combo{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;}
.case-combo em{font-style:normal;font-size:12.5px;background:var(--surface-2);border-radius:var(--radius-full);
  padding:5px 12px;color:var(--ink-2);}
.case-use{margin:0;font-size:13.5px;line-height:1.7;color:var(--ink-muted);}
@media(max-width:1024px){.case-grid{grid-template-columns:1fr;}}

/* 发展历程时间轴 */
.timeline{list-style:none;margin:0;padding:0;max-width:760px;}
.timeline li{position:relative;padding:0 0 30px 32px;border-left:2px solid var(--line);}
.timeline li:last-child{border-left-color:transparent;padding-bottom:0;}
.timeline li::before{content:"";position:absolute;left:-7px;top:5px;width:12px;height:12px;border-radius:50%;
  background:var(--brand);box-shadow:0 0 0 4px var(--brand-soft);}
.timeline .t-year{font-size:20px;font-weight:700;color:var(--brand);line-height:1.3;}
.timeline h4{font-size:17px;margin:5px 0 6px;}
.timeline p{font-size:14.5px;color:var(--ink-muted);line-height:1.8;margin:0;}

/* S08 首页转化区信息列 */
.cta-info .sec-h{margin:10px 0 14px;}
.cta-facts{list-style:none;margin:28px 0 0;padding:0;display:flex;flex-direction:column;gap:20px;}
.cta-facts .ci-k{display:block;font-size:13px;color:var(--ink-faint);margin-bottom:5px;}
.cta-facts .ci-v{font-size:17px;font-weight:600;color:var(--ink);}
a.ci-v{color:var(--brand);text-decoration:none;}

/* ===== 内页统一 Page Hero（产品 / 场景 / 实体 / 关于 / 知识 / 联系复用） ===== */
.page-hero{padding:64px 0 48px;background:var(--surface);border-bottom:1px solid var(--line-soft);}
.page-hero .eyebrow{display:inline-block;margin-bottom:14px;}
.ph-h{font-size:var(--fs-h1);line-height:1.25;letter-spacing:-.01em;color:var(--ink);margin:0 0 16px;font-weight:700;overflow-wrap:anywhere;}
@media(max-width:600px){.ph-h{font-size:26px;line-height:1.2;}}
.ph-lead{font-size:var(--fs-body-l);line-height:1.8;color:var(--ink-muted);margin:0;max-width:760px;}
.prod-hero{padding-bottom:48px;}
.prod-hero-in{display:grid;grid-template-columns:1.15fr .85fr;gap:56px;align-items:center;}
.prod-tag-line{display:inline-block;font-size:13px;font-weight:600;color:var(--brand);letter-spacing:.04em;margin-bottom:12px;}
.prod-hero-card{background:var(--surface-2);border:1px solid var(--line-soft);border-radius:var(--radius-lg);padding:24px;}
.phc-cap{display:block;font-size:13px;font-weight:600;color:var(--ink-2);margin-bottom:14px;}
.prod-mains{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:20px;}
.pm-k{font-size:13.5px;color:var(--ink-faint);}
.prod-mains em{font-style:normal;font-size:13.5px;background:var(--brand-soft);color:var(--brand);border-radius:var(--radius-full);padding:5px 13px;font-weight:500;}
.scene-links{display:flex;flex-wrap:wrap;gap:12px;}
.scene-chip{display:inline-flex;align-items:center;gap:8px;padding:12px 20px;border:1px solid var(--line);border-radius:var(--radius-full);
  font-size:14.5px;font-weight:500;color:var(--ink-2);background:var(--surface);transition:all var(--motion-fast);text-decoration:none;}
.scene-chip:hover{border-color:var(--brand);color:var(--brand);box-shadow:var(--shadow-hover);}
.scene-chip .arr{font-size:13px;}
.empty-note{color:var(--ink-faint);font-size:14.5px;padding:24px 0;}
/* 场景痛点三卡 */
.pain-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;}
.pain-card{background:var(--surface);border:1px solid var(--line-soft);border-radius:var(--radius-lg);padding:26px;}
.pain-card h3{margin:0 0 10px;font-size:17px;font-weight:600;color:var(--ink);}
.pain-card p{margin:0;font-size:14.5px;line-height:1.75;color:var(--ink-muted);}
/* 相邻场景 */
.adj-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.adj-card{display:flex;flex-direction:column;gap:8px;padding:24px 28px;border:1px solid var(--line-soft);border-radius:var(--radius-lg);
  background:var(--surface);text-decoration:none;transition:all var(--motion-fast);}
.adj-card:hover{border-color:var(--brand);box-shadow:var(--shadow-hover);transform:translateY(-2px);}
.adj-dir{font-size:13px;color:var(--ink-faint);}
.adj-name{font-size:18px;font-weight:600;color:var(--ink);display:flex;justify-content:space-between;align-items:center;}
.adj-card:hover .adj-name{color:var(--brand);}
@media(max-width:768px){.pain-grid{grid-template-columns:1fr;}.adj-grid{grid-template-columns:1fr;}}
/* 联系页事实列表 + 分页 */
.contact-facts{margin:0;display:flex;flex-direction:column;}
.contact-facts>div{padding:18px 0;border-bottom:1px solid var(--line-soft);}
.contact-facts>div:first-child{padding-top:0;}
.contact-facts dt{font-size:13px;color:var(--ink-faint);margin-bottom:6px;}
.contact-facts dd{margin:0;font-size:16.5px;font-weight:600;color:var(--ink);line-height:1.6;}
.contact-facts dd a{color:var(--brand);text-decoration:none;}
.contact-facts .map-link{display:inline-block;margin-top:8px;font-size:14px;font-weight:600;color:var(--cta-dark);text-decoration:none;}
.contact-facts .map-link:hover{text-decoration:underline;}
.contact-wechat{margin-top:28px;display:flex;align-items:center;gap:18px;background:var(--surface);border:1px solid var(--line-soft);
  border-radius:var(--radius-lg);padding:20px 22px;}
.contact-wechat img{width:132px;height:132px;flex:0 0 auto;border-radius:var(--radius-sm);background:var(--surface);}
.contact-wechat strong{display:block;font-size:16px;color:var(--ink);margin:0 0 6px;}
.contact-wechat span{display:block;font-size:13.5px;color:var(--ink-muted);line-height:1.6;}
@media(max-width:600px){.contact-wechat{flex-direction:column;text-align:center;}}
.pager{margin-top:40px;display:flex;justify-content:center;}
.pager nav ul.pagination{gap:6px;}
.pager .page-link{border:1px solid var(--line);border-radius:var(--radius-sm);color:var(--ink-2);padding:8px 14px;font-size:14px;}
.pager .page-item.active .page-link{background:var(--cta);border-color:var(--cta);color:var(--cta-on);}}

/* 关于：企业简介 7:5 + FactBlock */
.about-layout{display:grid;grid-template-columns:7fr 5fr;gap:56px;align-items:start;}
.about-prose{max-width:680px;}
.fact-block{margin:0;background:var(--surface);border:1px solid var(--line-soft);border-radius:var(--radius-lg);padding:30px 32px;
  display:grid;grid-template-columns:1fr;gap:16px;}
.fact-block>div{display:grid;grid-template-columns:96px 1fr;gap:16px;padding-bottom:16px;border-bottom:1px solid var(--line-soft);}
.fact-block>div:last-child{border-bottom:none;padding-bottom:0;}
.fact-block dt{font-size:13px;line-height:1.5;color:var(--ink-muted);}
.fact-block dd{margin:0;font-size:15px;line-height:1.6;font-weight:600;color:var(--ink);}
/* 企业文化 2×2 */
.culture-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.culture-card{background:var(--surface);border:1px solid var(--line-soft);border-top:3px solid var(--accent);border-radius:var(--radius);padding:36px 32px;}
.cc-label{font-size:13px;color:var(--ink-faint);letter-spacing:.06em;}
.cc-main{font-size:26px;line-height:1.45;font-weight:700;color:var(--ink);margin:12px 0 14px;}
.culture-card p{margin:0;font-size:15px;line-height:1.8;color:var(--ink-muted);}
.center-txt{text-align:center;}
@media(max-width:1024px){.about-layout{grid-template-columns:1fr;gap:32px;}.culture-grid{grid-template-columns:1fr;}}

/* 合作方式三卡 */
.coop-grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;}
.coop-mode{background:var(--surface);border:1px solid var(--line-soft);border-radius:var(--radius-lg);padding:30px;display:flex;flex-direction:column;
  transition:transform var(--motion-base),box-shadow var(--motion-base);}
.coop-mode:hover{transform:translateY(-3px);box-shadow:var(--shadow-hover);}
.coop-mode .feat-ic{margin-bottom:18px;}
.cm-h{margin:0 0 8px;font-size:20px;font-weight:700;}
.cm-fit{margin:0 0 16px;font-size:14px;color:var(--ink-muted);line-height:1.7;}
.coop-mode .coop-points{margin:0 0 18px;padding-left:0;list-style:none;display:flex;flex-direction:column;gap:10px;flex:1;}
.coop-mode .coop-points li{position:relative;padding-left:22px;font-size:14.5px;line-height:1.6;color:var(--ink-2);}
.coop-mode .coop-points li::before{content:"";position:absolute;left:2px;top:8px;width:7px;height:7px;border-radius:50%;background:var(--accent);}
.coop-mode .btn-text{margin-top:auto;}
@media(max-width:1024px){.coop-grid3{grid-template-columns:1fr;}}
/* 四列能力网格 */
.ws-grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;}
.ws4-card{background:var(--surface);border:1px solid var(--line-soft);border-radius:var(--radius-lg);padding:26px;
  transition:transform var(--motion-base),box-shadow var(--motion-base);}
.ws4-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-hover);}
.ws4-card .feat-ic{margin-bottom:16px;}
.ws4-card h3{margin:0 0 10px;font-size:17px;font-weight:600;}
.ws4-card p{margin:0;font-size:14px;line-height:1.7;color:var(--ink-muted);}
.ws4-img{width:100%;aspect-ratio:3/2;object-fit:cover;border-radius:var(--radius);margin-bottom:16px;background:var(--surface-2);}
.region-tags{display:flex;flex-wrap:wrap;gap:12px;}
.region-tags em{font-style:normal;font-size:15px;font-weight:500;background:var(--surface-2);border-radius:var(--radius-full);padding:9px 20px;color:var(--ink-2);}
@media(max-width:1024px){.ws-grid4{grid-template-columns:repeat(2,1fr);}}
@media(max-width:600px){.ws-grid4{grid-template-columns:1fr;}}
@media(max-width:1024px){.prod-hero-in{grid-template-columns:1fr;gap:32px;}}

/* 联系页 5:7（移动表单提前） */
.contact-grid{display:grid;grid-template-columns:5fr 7fr;gap:48px;align-items:start;}
.contact-info li{list-style:none;}
.contact-info ul{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:18px;}
.contact-info .ci-k{font-size:13px;color:var(--ink-faint);margin-bottom:4px;}
.contact-info .ci-v{font-size:16px;color:var(--ink);font-weight:600;}
.lead-form.standalone{max-width:none;padding:32px;}
.lead-form .lf-field{display:flex;flex-direction:column;gap:7px;}
.lead-form label{font-size:14px;font-weight:600;color:var(--ink-2);}
.lead-form input,.lead-form select,.lead-form textarea{width:100%;border:1px solid var(--line);border-radius:var(--radius-sm);
  padding:0 14px;font-size:16px;color:var(--ink);background:var(--surface);transition:border-color var(--motion-fast),box-shadow var(--motion-fast);font-family:inherit;}
.lead-form input,.lead-form select{height:48px;}
.lead-form textarea{padding:12px 14px;line-height:1.7;resize:vertical;}
.lead-form input:focus,.lead-form select:focus,.lead-form textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 2px var(--accent-ring);}
.lead-form .invalid{border-color:var(--brand)!important;}
.lead-form .lf-err{margin:0;color:var(--brand);font-size:12.5px;}
.lead-form .lf-privacy{margin:2px 0 0;color:var(--ink-faint);font-size:12.5px;line-height:1.6;}
.lead-form .lf-submit{margin-top:4px;}
.lead-form .lf-submit .btn{width:100%;}
.lead-ok{background:var(--accent-soft);border:1px solid var(--accent-soft);color:var(--cta-dark);border-radius:var(--radius);
  padding:22px;text-align:center;font-weight:600;line-height:1.7;}

/* ParamTable：仅水平分隔线、无竖线、行高 56、数值列右对齐等宽数字 */
.param-table-wrap{width:100%;}
.param-table{width:100%;border-collapse:collapse;background:var(--surface);border:1px solid var(--line-soft);border-radius:var(--radius);
  overflow:hidden;font-size:15px;}
.param-table thead th{background:var(--surface-2);text-align:left;font-size:13px;font-weight:600;color:var(--ink-muted);
  padding:0 20px;height:46px;border-bottom:1px solid var(--line-soft);}
.param-table tbody th{text-align:left;font-weight:600;color:var(--ink-2);padding:0 20px;height:56px;
  border-bottom:1px solid var(--line-soft);white-space:nowrap;}
.param-table tbody td{padding:0 20px;height:56px;border-bottom:1px solid var(--line-soft);color:var(--ink);line-height:1.6;}
.param-table tbody tr:last-child th,.param-table tbody tr:last-child td{border-bottom:0;}
.param-table .num{text-align:right;font-variant-numeric:tabular-nums;}
.param-table .pt-note{display:block;font-size:12.5px;color:var(--ink-faint);font-weight:400;margin-top:2px;}
.tbl-note{margin:12px 2px 0;font-size:13px;color:var(--ink-muted);line-height:1.7;}
.param-table.pt-inverse{background:transparent;}
/* 首屏侧栏「关键参数一览」紧凑变体：仅 3–5 行，去掉宽幅规格表的 56px 行高，避免卡片空高。
   仅桌面端紧凑化；移动端沿用下方统一的「参数卡片」响应式布局，不覆盖其内边距。 */
.prod-hero-card .param-table{font-size:14px;}
@media(min-width:1025px){
  .prod-hero-card{align-self:start;}
  .prod-hero-card .param-table thead th{height:40px;padding:0 16px;}
  .prod-hero-card .param-table tbody th,
  .prod-hero-card .param-table tbody td{height:auto;padding:13px 16px;}
}
@media(max-width:1024px){.prod-hero-card{align-self:stretch;}}

/* ProcessSteps：有序步骤（ol），序号圆 40（合作页 32 用 .ps-sm） */
.ps{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(var(--ps-cols,5),1fr);gap:20px;counter-reset:ps;}
.ps li{position:relative;padding-top:54px;counter-increment:ps;}
.ps li::before{content:counter(ps);position:absolute;top:0;left:0;width:40px;height:40px;border-radius:50%;
  background:var(--accent);color:var(--accent-on);font-weight:700;font-size:17px;display:grid;place-items:center;}
.ps h4{font-size:16px;margin:0 0 6px;}
.ps p{font-size:13.5px;line-height:1.7;color:var(--ink-muted);margin:0;}
.ps.ps-sm li{padding-top:46px;}
.ps.ps-sm li::before{width:32px;height:32px;font-size:15px;}

/* FAQ：details/summary，内容在初始 DOM（SEO/GEO），全站折叠 */
.faq-list{display:flex;flex-direction:column;gap:12px;}
.faq-list details{border:1px solid var(--line-soft);border-radius:var(--radius);background:var(--surface);overflow:hidden;
  transition:border-color var(--motion-fast),box-shadow var(--motion-fast);}
.faq-list details[open]{border-color:var(--line);box-shadow:var(--shadow-sm);}
.faq-list summary{list-style:none;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:16px;
  padding:18px 20px;font-size:16px;font-weight:600;color:var(--ink);line-height:1.5;}
.faq-list summary::-webkit-details-marker{display:none;}
.faq-list summary .faq-ic{flex:none;width:20px;height:20px;color:var(--brand);transition:transform var(--motion-base);}
.faq-list details[open] summary .faq-ic{transform:rotate(45deg);}
.faq-list .faq-a{padding:0 20px 18px;color:var(--ink-muted);font-size:14.5px;line-height:1.85;}

/* 区块标题统一 */
.sec-head{max-width:760px;margin-bottom:40px;}
.sec-head.center{margin-left:auto;margin-right:auto;text-align:center;}
.sec-head .eyebrow{display:inline-block;font-size:13px;font-weight:600;color:var(--brand);letter-spacing:.08em;margin-bottom:12px;}
.sec-head h2{margin:0 0 14px;}
.sec-head p{margin:0;color:var(--ink-muted);font-size:16px;line-height:1.8;}

/* 内页正文排版 */
.prose{font-size:16px;line-height:1.9;color:var(--ink-2);}
.prose p{margin:0 0 16px;}
.prose h2{font-size:var(--fs-h3);margin:32px 0 14px;}
.prose h3{font-size:var(--fs-h4);margin:24px 0 10px;}
.prose ul{margin:0 0 16px;padding-left:22px;}
.prose li{margin-bottom:8px;}

/* 页脚五列（品牌 + 产品 + 场景 + 关于 + 联系[最右]）；联系列略宽，承载电话/地址/二维码 */
.ft-grid{grid-template-columns:1.5fr repeat(3,1fr) 1.2fr;gap:30px;}
.ft-col h4{margin-bottom:14px;}
.ft-col li{margin-bottom:10px;}
.ft-facts{margin-top:16px;font-size:12.5px;line-height:1.9;color:var(--footer-dim);max-width:300px;}
.ft-col .ft-qr{display:flex;flex-direction:column;align-items:flex-start;gap:8px;margin-top:4px;}
.ft-col .ft-qr img{width:104px;height:104px;background:var(--surface);padding:6px;border-radius:8px;}
.ft-col .ft-qr span{font-size:12.5px;color:var(--footer-dim);}
/* 联系列：标签 / 值上下堆叠，号码不换行，地址可换行，杜绝号码中间折断 */
.ft-c-contact .ft-contact-line{display:flex;flex-direction:column;gap:3px;margin-bottom:14px;}
.ft-c-contact .ft-k{font-size:12.5px;color:var(--footer-dim);}
.ft-c-contact .ft-v{font-size:14px;line-height:1.6;color:var(--footer-ink);white-space:nowrap;}
a.ft-v:hover{color:#fff;}
.ft-c-contact .ft-contact-line--wrap .ft-v{white-space:normal;line-height:1.7;}
.ft-c-contact .ft-qr{margin-top:6px;}

@media(max-width:1024px){
  .prod-grid{grid-template-columns:repeat(3,1fr);}
  .contact-grid{grid-template-columns:1fr;gap:32px;}
  /* 平板：品牌区整行，四个链接列 2×2，联系列落在右下 */
  .ft-grid{grid-template-columns:repeat(2,1fr);}
  .ft-brand{grid-column:1 / -1;}
}
@media(max-width:768px){
  .hd-in{min-height:56px;}
  .subnav{top:56px;}
  /* 移动端二级导航为容器内横滑标签条：子项不压缩、间距收紧、滚动边界收敛，绝不引发页面级横向滚动 */
  .subnav-in{gap:24px;overscroll-behavior-x:contain;-webkit-overflow-scrolling:touch;}
  .subnav a{flex:0 0 auto;}
  /* 移动端导航面板展开为静态手风琴 */
  .nav{gap:0;}
  .nav>li{width:100%;}
  .nav>li>a{padding:15px 24px;font-size:16px;}
  .nav .caret{display:none;}
  .nav>li::after{display:none;}
  .nav-panel{position:static;transform:none;opacity:1;visibility:visible;box-shadow:none;border:0;
    border-radius:0;padding:0 24px 8px 36px;min-width:0;}
  .nav-panel a{padding:9px 0;font-size:14px;color:var(--ink-muted);}
  /* 抽屉链接交互态统一锁定（修复触摸/悬停后文字发白、浅灰底上对比不足）：
     default/visited/hover/focus/active 全部显式给色，任何状态都不得出现白字；
     交互态与桌面下拉同源——浅灰底 + 品牌红字；并关闭原生点击高亮闪烁。 */
  .hd-in nav .nav a,
  .hd-in nav .nav a:visited{color:var(--ink-2);-webkit-tap-highlight-color:transparent;}
  .hd-in nav .nav-panel a,
  .hd-in nav .nav-panel a:visited{color:var(--ink-muted);}
  .hd-in nav .nav a:hover,
  .hd-in nav .nav a:focus,
  .hd-in nav .nav a:active,
  .hd-in nav .nav-panel a:hover,
  .hd-in nav .nav-panel a:focus,
  .hd-in nav .nav-panel a:active{background:var(--surface-2);color:var(--brand);border-bottom-color:transparent;}
  .hd-in nav .nav a:focus-visible,
  .hd-in nav .nav-panel a:focus-visible{outline:2px solid var(--brand);outline-offset:-2px;}
  .prod-grid{grid-template-columns:repeat(2,1fr);gap:12px;}
  .bcta{padding:48px 0;}
  .bcta h2{font-size:24px;}
  .wrap-wide,.wrap-narrow{padding:0 16px;}
  /* 移动页脚：联系列最前 */
  .ft-grid{grid-template-columns:1fr;gap:24px;}
  .ft-brand{order:5;}
  .ft-c-contact{order:1;}.ft-c-product{order:2;}.ft-c-scene{order:3;}.ft-c-about{order:4;}
  /* 移动联系页表单提到信息之前（表单包裹列是 grid 直接子项） */
  .contact-grid .contact-form-col{order:-1;}
  /* ParamTable 移动键值卡（不横滚） */
  .param-table thead{display:none;}
  .param-table,.param-table tbody,.param-table tr,.param-table th,.param-table td{display:block;width:100%;}
  .param-table{border:0;background:transparent;}
  .param-table tbody tr{background:var(--surface);border:1px solid var(--line-soft);border-radius:var(--radius);margin-bottom:10px;padding:14px 16px;}
  .param-table tbody th{height:auto;padding:0 0 6px;border:0;white-space:normal;font-size:13px;color:var(--ink-faint);text-align:left;}
  .param-table tbody td{height:auto;padding:0;border:0;text-align:left!important;font-size:15px;}
  .param-table tbody td::before{content:attr(data-label);position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);}
  .param-table tbody td.num{text-align:left;}
  /* 反白区（.is-inverse）参数表在移动端转白卡，必须把为深底设置的浅色字覆盖回深色，避免白底浅字看不清 */
  .is-inverse .param-table tbody tr{background:var(--surface);border-color:var(--line-soft);}
  .is-inverse .param-table tbody th{color:var(--ink-muted);}
  .is-inverse .param-table tbody td{color:var(--ink);}
  .is-inverse .param-table .pt-note{color:var(--ink-faint);}
  /* 步骤移动纵向 */
  .ps{grid-template-columns:1fr;gap:18px;}
  .ps li{padding:0 0 0 54px;min-height:40px;}
  .ps li::before{left:0;top:0;}
  .faq-list summary{padding:15px 16px;font-size:15px;}
  .faq-list .faq-a{padding:0 16px 15px;}
}
@media(max-width:600px){
  .prod-grid{grid-template-columns:repeat(2,1fr);gap:10px;}
  /* 场景推荐组合：移动端单列、横向卡，避免 1:1 大图位留白 */
  .combo-grid{grid-template-columns:1fr;gap:12px;}
  .combo-grid .prod-card{flex-direction:row;align-items:stretch;}
  .combo-grid .prod-card::before{display:none;}
  .combo-grid .prod-img{flex:0 0 96px;width:96px;aspect-ratio:auto;min-height:96px;border-right:1px solid var(--line-soft);}
  .combo-grid .prod-img .ph-ic{width:34px;height:34px;}
  .combo-grid .prod-bd{justify-content:center;}
  .prod-bd{padding:12px 13px 14px;gap:6px;}
  .prod-bd h3{font-size:14px;line-height:20px;}
  .bcta-phone a{font-size:19px;display:inline-block;margin-top:6px;margin-left:0;}
}
</style>
@if(!empty($siteSettings['theme_custom_css']))
<style>{!! $siteSettings['theme_custom_css'] !!}</style>
@endif

{!! app(\App\Services\Geo\SchemaBuilder::class)->render($schemas ?? []) !!}

@if(!empty($siteSettings['seo_head_code']))
{!! $siteSettings['seo_head_code'] !!}
@endif
</head>
<body>

<header class="hd" id="siteHeader">
  <input type="checkbox" id="nav-toggle" aria-label="{{ config('copy.nav.ariaLabels.openMenu') ?? '打开导航菜单' }}">
  <div class="wrap hd-in">
    <a class="logo" href="{{ url('/') }}" aria-label="{{ $siteSettings['site_name'] ?? config('app.name') }}首页">
      <img src="{{ asset(!empty($siteSettings['geo_org_logo']) ? $siteSettings['geo_org_logo'] : 'img/logo.png') }}"
           alt="{{ $siteSettings['site_name'] ?? config('app.name') }}" height="40">
    </a>
    <nav aria-label="{{ config('copy.nav.ariaLabels.primaryNav') ?? '主导航' }}"><ul class="nav" id="primary-nav" style="margin:0;padding:0;">
      @foreach(($mainMenu ?? []) as $m)
        @php
          $on = false;
          foreach (($m['patterns'] ?? []) as $pat) { if (request()->is($pat)) { $on = true; break; } }
          $hasChildren = ! empty($m['children']);
        @endphp
        <li>
          <a href="{{ $m['url'] }}" class="{{ $on ? 'on' : '' }}" @if($on) aria-current="page" @endif
             @if(!empty($m['external'])) target="_blank" rel="noopener" @endif
             @if($hasChildren && $m['url'] === '#') data-no-jump="1" aria-haspopup="true" @endif>
            {{ $m['name'] }}
            @if($hasChildren)
              <svg class="caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            @endif
          </a>
          @if($hasChildren)
            <div class="nav-panel">
              @foreach($m['children'] as $ch)
                <a href="{{ $ch['url'] }}" @if(!empty($ch['external'])) target="_blank" rel="noopener" @endif>{{ $ch['name'] }}</a>
              @endforeach
            </div>
          @endif
        </li>
      @endforeach
    </ul>
    </nav>
    <div class="hd-right">
      @php
        $navPhone = config('copy.nav.phone') ?: ($siteSettings['contact_phone'] ?? '');
        $telBase = config('copy.nav.ariaLabels.phone') ?: '拨打合作热线';
        $telAria = is_string($telBase) && str_contains($telBase, $navPhone) ? $telBase : $telBase.' '.$navPhone;
      @endphp
      @if(!empty($navPhone))
        <a class="hd-tel" href="tel:{{ config('copy.nav.phoneTel') ?: preg_replace('/[^0-9]/', '', $navPhone) }}"
           aria-label="{{ $telAria }}">
          <svg class="hd-tel-ic" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
          <span class="hd-tel-num">{{ $navPhone }}</span>
        </a>
      @endif
      @if($allowDark)
      <button type="button" class="theme-mode-toggle" data-theme-toggle aria-label="{{ __('ui.mode_aria_toggle') }}" title="{{ __('ui.mode_aria_toggle') }}">
        <svg class="tmt-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
        <svg class="tmt-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        <svg class="tmt-sys" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
      </button>
      @endif
      @include('site.partials.locale-switcher')
      <a class="btn btn-sm hd-cta" href="{{ url('/') }}#s08">{{ $ctaText ?? (config('copy.nav.cta') ?? '联系我们') }}</a>
      <label class="nav-toggle" for="nav-toggle" aria-label="菜单"><span></span><span></span><span></span></label>
    </div>
  </div>
</header>

<main>
@if(!empty($crumbs))
  <div class="wrap">
    <nav class="crumb" aria-label="面包屑导航">
      <ol>
        <li><a href="{{ url('/') }}">{{ __('nav.home') }}</a></li>
        @foreach($crumbs as $c)
          <li><span class="sep" aria-hidden="true">/</span>
            @if(!empty($c['url']) && ! $loop->last)<a href="{{ $c['url'] }}">{{ $c['name'] }}</a>
            @else<span aria-current="page">{{ $c['name'] }}</span>@endif
          </li>
        @endforeach
      </ol>
    </nav>
  </div>
@endif

@yield('content')
</main>

@php
  // 页脚列与链接由后台「结构 → 导航菜单」运营（$footerMenu 已叠加覆盖层与自定义挂列项）；
  // 联系列的电话 / 手机 / 地址 / 二维码取值自站点设置，不在此写死。
  $footer = config('copy.footer', []);
  $ftCols = $footerMenu;
  $ftColClass = [
      'ft-col-contact' => 'ft-c-contact',
      'ft-col-products' => 'ft-c-product',
      'ft-col-solutions' => 'ft-c-scene',
      'ft-col-about' => 'ft-c-about',
  ];
  $ftBrand = $footer['brandColumn'] ?? [];
  // 底部只保留法定主体名（NAP/内链从简）。成立 / 投产 / 规模 / 产能 / 网点 / 销售区为“实力证据”，
  // 权威出处放在关于页、实体 / 资质页、Organization 结构化数据与 llms.txt，不在全站每页底部重复堆高。
  $ftFacts = array_values(array_filter([
      $ftBrand['companyName'] ?? null,
  ]));
  $ftLegal = $footer['legal'] ?? [];
  // 当前语言公司名：非默认语言（/en）优先英文主体名（geo_org_en_name），缺省回退站点名
  $ftIsEn = \App\Support\Localization\LocaleContext::current() !== \App\Support\Localization\LocaleRegistry::default();
  $ftCompanyName = $ftIsEn
      ? (trim((string) ($siteSettings['geo_org_en_name'] ?? '')) !== ''
          ? $siteSettings['geo_org_en_name']
          : ($siteSettings['site_name'] ?? config('app.name')))
      : ($siteSettings['site_name'] ?? config('app.name'));
  $ftPhone = trim((string) ($siteSettings['contact_phone'] ?? ''));
  $ftMobile = trim((string) ($siteSettings['contact_mobile'] ?? ''));
  $ftAddress = trim((string) ($siteSettings['contact_address'] ?? ''));
  $ftQrSrc = trim((string) ($siteSettings['contact_wechat_qr'] ?? ''));
  $ftTel = fn ($v) => 'tel:' . preg_replace('/[^0-9]/', '', (string) $v);
  // 相对路径走 url()，tel/mailto/http/锚点原样
  $ftHref = function ($h) {
      if ($h === null || $h === '') return 'javascript:void(0)';
      if (preg_match('~^(tel:|mailto:|https?://)~', $h)) return $h;
      return url('/' . ltrim($h, '/'));
  };
@endphp
<footer class="ft">
  <div class="wrap">
    <div class="ft-grid">
      <div class="ft-brand">
        <img src="{{ asset(!empty($siteSettings['geo_org_logo']) ? $siteSettings['geo_org_logo'] : 'img/logo.png') }}"
             alt="{{ $siteSettings['site_name'] ?? config('app.name') }}" height="40">
        <p class="ft-desc">{{ \App\Support\Copy::footerSlogan() }}</p>
        @if(!empty($ftFacts))
          <ul class="ft-facts">
            @foreach($ftFacts as $ff)<li>{{ $ff }}</li>@endforeach
          </ul>
        @endif
      </div>
      @foreach($ftCols as $col)
        @php
          // 联系列的动态项（业务手机）无值时不渲染
          $colItems = array_filter($col['items'] ?? [], function ($l) use ($ftMobile) {
              return ! (($l['key'] ?? '') === 'ft-contact-mobile' && $ftMobile === '');
          });
          if (! $colItems) { continue; }
        @endphp
        <div class="ft-col {{ $ftColClass[$col['key']] ?? '' }}">
          <h4>{{ $col['title'] }}</h4>
          <ul>
            @foreach($colItems as $l)
              @if(($l['type'] ?? '') === 'qr')
                @if(!empty($ftQrSrc))
                <li class="ft-qr">
                  <img src="{{ asset($ftQrSrc) }}" alt="联系二维码" width="104" height="104" loading="lazy">
                  <span>{{ $l['name'] ?? $l['label'] ?? '扫码联系' }}</span>
                </li>
                @endif
              @elseif(($l['type'] ?? '') === 'text')
                @php
                  // 联系列值由站点设置驱动（覆盖层只改显示名称/显隐）
                  if (($l['key'] ?? '') === 'ft-contact-hotline') {
                      $lValue = $ftPhone; $lHref = $ftTel($ftPhone);
                  } elseif (($l['key'] ?? '') === 'ft-contact-mobile') {
                      $lValue = $ftMobile; $lHref = $ftTel($ftMobile);
                  } elseif (($l['key'] ?? '') === 'ft-contact-address') {
                      $lValue = $ftAddress; $lHref = '';
                  } else {
                      $lValue = $l['value'] ?? ($l['name'] ?? '');
                      $lHref = $l['url'] ?? '';
                  }
                  // 热线 / 手机 / 地址无值时整行不渲染（不输出空标签）
                  if (in_array($l['key'] ?? '', ['ft-contact-hotline', 'ft-contact-mobile', 'ft-contact-address'], true)
                      && trim((string) $lValue) === '') {
                      continue;
                  }
                @endphp
                <li class="ft-contact-line{{ ($l['key'] ?? '') === 'ft-contact-address' ? ' ft-contact-line--wrap' : '' }}">
                  <span class="ft-k">{{ $l['name'] ?? $l['label'] ?? '' }}</span>
                  @if(!empty($lHref))<a class="ft-v" href="{{ $ftHref($lHref) }}">{{ $lValue }}</a>
                  @else<span class="ft-v">{{ $lValue }}</span>@endif
                </li>
              @else
                <li><a href="{{ $l['url'] ?? '#' }}" @if(!empty($l['external'])) target="_blank" rel="noopener" @endif>{{ $l['name'] ?? $l['label'] }}</a></li>
              @endif
            @endforeach
          </ul>
        </div>
      @endforeach
      @if(!empty($footerExtra))
        <div class="ft-col ft-c-extra">
          <h4>{{ __('nav.quick_links') }}</h4>
          <ul>
            @foreach($footerExtra as $fe)
              <li><a href="{{ $fe['url'] }}" @if(!empty($fe['external'])) target="_blank" rel="noopener" @endif>{{ $fe['name'] }}</a></li>
            @endforeach
          </ul>
        </div>
      @endif
    </div>
    <div class="ft-btm">
      <span>{{ $ftLegal['copyright'] ?? ('© ' . date('Y') . ' ' . $ftCompanyName) }}</span>
      @if(!empty($ftLegal['icp']))
        <span><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener">{{ $ftLegal['icp'] }}</a></span>
      @elseif(!empty($siteSettings['icp_number']))
        <span><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener">{{ $siteSettings['icp_number'] }}</a></span>
      @endif
      @if(!empty($siteSettings['police_number']))
        <span class="ft-police"><a href="http://www.beian.mps.gov.cn/" target="_blank" rel="noopener">{{ $siteSettings['police_number'] }}</a></span>
      @endif
      @if(!empty($ftLegal['scLicense']))<span>{{ $ftLegal['scLicense'] }}</span>@endif
      @if(!empty($ftLegal['standardCode']))<span>{{ $ftLegal['standardCode'] }}</span>@endif
    </div>
  </div>
</footer>

{{-- 信任数字滚动增长 + 区块滚动出现：纯原生、无框架；减少动态偏好时直接显示终值（DOM 已含终值，不影响 SEO/GEO） --}}
<script nonce="{{ $cspNonce ?? '' }}">
(function(){
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* M9 导航滚动收缩：80 → 64（移动端 56 固定）。加迟滞（>16 才收缩、<8 才还原），
     避免在顶部附近惯性滚动时跨阈值反复切换，导致整条导航抖动。 */
  var hd = document.getElementById('siteHeader');
  if(hd){
    var shrunk = false;
    var onScroll = function(){
      var y = window.scrollY;
      if(!shrunk && y > 16){ shrunk = true; hd.classList.add('scrolled'); }
      else if(shrunk && y < 8){ shrunk = false; hd.classList.remove('scrolled'); }
    };
    window.addEventListener('scroll', onScroll, {passive:true}); onScroll();
  }

  /* 桌面端一级导航下拉：JS 接管开关（仅 ≥769px；移动端是静态手风琴，不介入）。
     兄弟菜单互斥、离开后 120ms 延时关闭，鼠标斜向穿过一级项与弹层间隙、或在紧贴导航的
     首屏大图顶部移动时都不会反复闪烁（无 JS 时 CSS :hover 仍可兜底）。 */
  (function(){
    var mq = window.matchMedia ? window.matchMedia('(min-width:769px)') : {matches:true};
    document.querySelectorAll('.nav > li').forEach(function(li){
      if(!li.querySelector('.nav-panel')) return;
      var timer = null;
      function cancel(){ if(timer){ clearTimeout(timer); timer = null; } }
      function open(){
        if(!mq.matches) return;
        cancel();
        li.parentElement.querySelectorAll(':scope > li.open').forEach(function(o){ if(o !== li) o.classList.remove('open'); });
        li.classList.add('open');
      }
      function close(){ cancel(); timer = setTimeout(function(){ li.classList.remove('open'); }, 120); }
      li.addEventListener('mouseenter', open);
      li.addEventListener('mouseleave', close);
      li.addEventListener('focusin', open);
      li.addEventListener('focusout', close);
    });
  })();

@if($allowDark)
  /* 外观模式切换：点击在 浅色 -> 深色 -> 跟随系统 间循环，写 localStorage 并即时切换；
     跟随系统下由 head 引导脚本监听 prefers-color-scheme 变化。纯视觉，无业务数据。 */
  (function(){
    var btns=document.querySelectorAll('[data-theme-toggle]');
    if(!btns.length) return;
    var order=['light','dark','system'];
    var labels={light:{{ json_encode(__('ui.mode_light')) }},dark:{{ json_encode(__('ui.mode_dark')) }},system:{{ json_encode(__('ui.mode_system')) }}};
    var ariaCurrent={{ json_encode(__('ui.mode_aria_current')) }};
    function sysDark(){ return window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches; }
    function paint(pref){
      var root=document.documentElement;
      root.dataset.colorModePref=pref;
      root.setAttribute('data-color-scheme', pref==='system' ? (sysDark()?'dark':'light') : pref);
    }
    btns.forEach(function(btn){
      btn.addEventListener('click',function(){
        var cur=document.documentElement.dataset.colorModePref || 'light';
        var next=order[(order.indexOf(cur)+1)%order.length];
        try{ localStorage.setItem('gwos-color-mode',next); }catch(e){}
        paint(next);
        btn.setAttribute('aria-label', ariaCurrent.replace('{mode}', labels[next]));
        btn.title=ariaCurrent.replace('{mode}', labels[next]);
      });
    });
  })();
@endif

  /* 移动端抽屉：打开时锁定「背景页面」滚动（拦截抽屉外的 touchmove / wheel），
     抽屉面板内部仍可独立滚动；关闭/点链接后移除监听。不修改 body/overflow，
     故吸顶 header 与背景滚动位置都保持不变。 */
  (function(){
    var tg = document.getElementById('nav-toggle');
    if(!tg) return;
    var locked = false;
    function insideNav(el){ return el && el.closest && el.closest('.hd-in nav'); }
    function onTouchMove(e){
      if(!insideNav(e.target)) e.preventDefault(); /* 背景触摸滑动：阻止 */
    }
    function onWheel(e){
      if(!insideNav(e.target)) e.preventDefault(); /* 鼠标滚轮作用于背景：阻止 */
    }
    function lock(){
      if(locked) return; locked = true;
      document.addEventListener('touchmove', onTouchMove, {passive:false});
      document.addEventListener('wheel', onWheel, {passive:false});
    }
    function unlock(){
      if(!locked) return; locked = false;
      document.removeEventListener('touchmove', onTouchMove);
      document.removeEventListener('wheel', onWheel);
    }
    tg.addEventListener('change', function(){ tg.checked ? lock() : unlock(); });
    window.addEventListener('resize', function(){
      if(window.innerWidth > 768 && tg.checked){ tg.checked = false; unlock(); }
    });
    /* 抽屉内链接（含页内锚点，不触发整页跳转）点击后关闭并解锁；
       纯父级菜单（href="#"，仅用于展开二级）不跳转、不关闭抽屉 */
    document.querySelectorAll('.hd-in nav a').forEach(function(a){
      a.addEventListener('click', function(e){
        if(a.getAttribute('data-no-jump') === '1'){ e.preventDefault(); return; }
        if(tg.checked){ tg.checked = false; unlock(); }
      });
    });
  })();

  /* 数字滚动 */
  var nums = document.querySelectorAll('[data-count]');
  function fmt(n){ return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function run(el){
    var target = parseInt(el.getAttribute('data-count'), 10) || 0;
    if(reduce){ el.textContent = fmt(target); return; }
    var start = null, dur = 1100;
    el.textContent = '0';
    function step(ts){
      if(!start) start = ts;
      var p = Math.min((ts - start) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = fmt(Math.round(target * eased));
      if(p < 1) requestAnimationFrame(step); else el.textContent = fmt(target);
    }
    requestAnimationFrame(step);
  }

  /* 区块轻入场 */
  var rev = document.querySelectorAll('.reveal');
  var io = ('IntersectionObserver' in window) ? new IntersectionObserver(function(entries){
    entries.forEach(function(e){
      if(e.isIntersecting){
        var t = e.target;
        if(t.hasAttribute('data-count')) run(t);
        t.classList.add('in');
        io.unobserve(t);
      }
    });
  }, {threshold:.12, rootMargin:'0px 0px -8% 0px'}) : null;

  nums.forEach(function(el){ if(io && !reduce) io.observe(el); else run(el); });
  if(io && !reduce){ rev.forEach(function(el){ io.observe(el); }); }
  else { rev.forEach(function(el){ el.classList.add('in'); }); }
})();
</script>
</body>
</html>
