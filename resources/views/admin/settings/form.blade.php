@extends('admin.layout')
@section('title','站点设置')
@section('page-desc','公司信息、联系方式、全站文案、外观主题、SEO / GEO 与 GEOFlow 对接的全局设置，前台统一从此取值。')

@section('content')
<div class="filter-bar">
  @foreach(\App\Http\Controllers\Admin\SettingController::GROUPS as $gk=>$gv)
    <a class="tab {{ $group===$gk ? 'on' : '' }}" href="{{ route('admin.settings.index',$gk) }}">{{ $gv }}</a>
  @endforeach
</div>

<div class="card narrow-md">
  <h2>{{ \App\Http\Controllers\Admin\SettingController::GROUPS[$group] }}</h2>

  @if($group==='theme')
  {{-- 行业视觉预设（P-STEP 18D）：仅写 theme_* 视觉令牌，绝不触碰 IA / 导航 / 文案 / 内容 --}}
  <div style="border:1px solid #e6e9ee;border-radius:12px;padding:16px;margin-bottom:18px;background:#fbfcfe">
    <div style="font-weight:600;margin-bottom:4px">行业视觉预设</div>
    <p class="small muted" style="margin:0 0 4px">一键切换配色 / 圆角 / 版面密度 / 阴影质感。<strong>只改变外观</strong>，不会改动导航、栏目、文案或任何内容；行业示例内容需另行加载 Demo 数据（Blank System ≠ Demo Site）。</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(224px,1fr));gap:12px;margin-top:12px">
      @foreach((array) config('theme-presets') as $pkey => $p)
        <form method="post" action="{{ route('admin.settings.preset') }}"
              style="border:1px solid #e6e9ee;border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:8px;background:#fff"
              onsubmit="return confirm('应用「{{ $p['label'] }}」预设？仅覆盖外观设置，不影响内容与导航。')">
          @csrf
          <input type="hidden" name="preset" value="{{ $pkey }}">
          <div style="display:flex;gap:6px">
            @foreach($p['swatch'] as $sw)
              <span style="width:22px;height:22px;border-radius:50%;background:{{ $sw }};border:1px solid rgba(0,0,0,.10);display:inline-block"></span>
            @endforeach
          </div>
          <strong style="font-size:14px">{{ $p['label'] }}</strong>
          <span class="small muted" style="flex:1;line-height:1.5">{{ $p['description'] }}</span>
          <button class="btn" style="align-self:flex-start">应用预设</button>
        </form>
      @endforeach
    </div>
    <hr style="border:none;border-top:1px solid #eceff3;margin:16px 0 10px">
    <div class="small muted">也可在下方逐项微调「品牌主色 / 辅色（Brand Seed）」：悬停、浅底、反白文字、CTA 与首屏深色渐变会自动派生，并保证文字对比度达到 WCAG AA；「主色（深）」留空即自动派生。</div>
  </div>
  @endif

  @if($group==='theme')
  {{-- 品牌资源（RC-11 集中化）：Logo / Favicon / Apple Touch / OG 图统一在这里上传，
       不再散落到 geo / seo / 站点设置多处。未上传时前台自动用内置默认图。 --}}
  <form method="post" action="{{ route('admin.settings.brand-assets') }}" enctype="multipart/form-data" class="brand-assets">
    @csrf
    <div class="brand-assets-hd">
      <div class="brand-assets-title">品牌资源</div>
      <p class="small muted" style="margin:0">
        在此统一管理站点标识与图标。<strong>全部可留空</strong> —— 未上传时前台自动使用系统内置默认标识。
      </p>
    </div>

    <div class="brand-grid">
      @php
        // key => [label, 当前值, 尺寸建议, 前台用途]
        $brandAssets = [
          'geo_org_logo' => ['站点 Logo', '方形图，建议 ≥512×512 PNG', '顶栏 + 页脚 + SEO/Schema'],
          'seo_og_image' => ['社交分享图', '1200×630（1.91:1）', '微信/微博等分享缩略图'],
        ];
        $faviconAssets = [
          'favicon_ico'  => ['Favicon（.ico）', '16/32/48 多尺寸，≤2MB', '浏览器标签页图标'],
          'favicon_png'  => ['Favicon（PNG）', '32×32 或 64×64 PNG', '现代浏览器首选'],
          'favicon_16'   => ['Favicon 16px', '16×16 PNG', '小尺寸回退'],
          'apple_touch'  => ['Apple Touch Icon', '180×180 PNG', 'iPhone/iPad 主屏'],
        ];
        $currentSite = \App\Support\SiteContext::currentSite();
      @endphp

      @foreach($brandAssets as $bk => [$bLabel, $bSize, $bUse])
        @php($bVal = \App\Models\Setting::get($bk, ''))
        <div class="brand-card">
          <div class="brand-preview">
            @if($bVal)<img src="{{ asset($bVal) }}" alt="{{ $bLabel }} 预览">@else<span class="brand-ph">默认</span>@endif
          </div>
          <div class="brand-meta">
            <div class="brand-name">{{ $bLabel }}</div>
            <div class="brand-size">{{ $bSize }}</div>
            <div class="brand-use muted">{{ $bUse }}</div>
          </div>
          <div class="brand-ctl">
            <input type="text" name="{{ $bk }}" value="{{ old($bk, $bVal) }}" list="media-paths" placeholder="留空 = 使用内置默认">
            <input type="file" name="file_{{ $bk }}" accept="image/png,image/jpeg,image/webp,image/gif" class="file-input">
            <label class="checkline small">
              <input type="checkbox" name="clear_asset[]" value="{{ $bk }}" @if(!$bVal)disabled @endif>
              清除自定义，恢复默认
            </label>
          </div>
        </div>
      @endforeach

      @foreach($faviconAssets as $fk => [$fLabel, $fSize, $fUse])
        @php($fPath = public_path('img/brand/' . $fk . '.png'))
        @php($fHas = file_exists($fPath))
        <div class="brand-card">
          <div class="brand-preview">
            @if($fHas)<img src="{{ asset('img/brand/' . $fk . '.png') }}?v={{ filemtime($fPath) }}" alt="{{ $fLabel }} 预览">@else<span class="brand-ph">默认</span>@endif
          </div>
          <div class="brand-meta">
            <div class="brand-name">{{ $fLabel }}</div>
            <div class="brand-size">{{ $fSize }}</div>
            <div class="brand-use muted">{{ $fUse }}</div>
          </div>
          <div class="brand-ctl">
            {{-- 图标走文件上传，由 SettingController 写入 public/img/brand/ --}}
            <input type="file" name="brand_icon_{{ $fk }}" accept="image/png,image/x-icon,image/vnd.microsoft.icon" class="file-input">
            <span class="small muted">{{ $fHas ? '已上传，可直接替换' : '未上传，使用内置默认' }}</span>
          </div>
        </div>
      @endforeach
    </div>

    <div class="form-actions">
      <button class="btn btn-primary">保存品牌资源</button>
      <span class="small muted">留空或清除 = 使用系统内置默认标识，前台不会出现破图。</span>
    </div>
  </form>
  @endif

  <form method="post" action="{{ route('admin.settings.update',$group) }}" enctype="multipart/form-data">
    @csrf @method('PUT')

    @foreach($settings as $s)
      <div class="form-row">
        <label><span class="label-with-tip">{{ $s->label }}
          @if($s->hint)<x-admin-tip type="info" :text="$s->hint"/>@endif
          <span class="small muted mono">[{{ $s->key }}]</span>
        </span></label>

        @if($s->key==='site_supported_locales')
          @php($locVal = is_array($s->value) ? $s->value : [])
          <div style="display:flex;flex-direction:column;gap:8px">
            @foreach(\App\Support\Localization\LocaleRegistry::supported() as $locOpt)
              <label class="checkline">
                <input type="checkbox" name="site_supported_locales[]" value="{{ $locOpt }}"
                       @checked(in_array($locOpt, $locVal, true))>
                {{ $locOpt === 'en' ? 'English' : '中文' }}（{{ $locOpt }}）
              </label>
            @endforeach
          </div>
          <div class="help mt-1">勾选后前台出现对应语言；默认语言始终保留。</div>
        @elseif($s->type==='bool')
          <label class="checkline">
            <input type="hidden" name="{{ $s->key }}" value="0">
            <input type="checkbox" name="{{ $s->key }}" value="1" @checked(($s->value ?? '')==='1' || $s->value===1)>
            开启
          </label>
        @elseif($s->type==='select')
          @php($selectOptions = match($s->key){
            'theme_color_mode' => ['light'=>'浅色（默认）','dark'=>'深色','system'=>'跟随系统'],
            'theme_typography' => ['standard'=>'标准（默认）','compact'=>'紧凑（信息密集）','editorial'=>'编辑感（大留白）'],
            default => [],
          })
          <select name="{{ $s->key }}">
            @foreach($selectOptions as $ov => $ol)
              <option value="{{ $ov }}" @selected(old($s->key, $s->value)===$ov)>{{ $ol }}</option>
            @endforeach
          </select>
        @elseif($s->type==='textarea')
          <textarea name="{{ $s->key }}" rows="4">{{ old($s->key, $s->value) }}</textarea>
        @elseif($s->type==='number')
          <input type="number" name="{{ $s->key }}" value="{{ old($s->key, $s->value) }}">
        @elseif($s->type==='color')
          @php($colorVal = old($s->key, $s->value))
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <input type="color" name="{{ $s->key }}" value="{{ $colorVal ?: '#E5E7EB' }}" class="color-input">
            @unless($colorVal)
              <span class="small muted">当前为系统默认（灰色仅为占位，不改动则保持默认）</span>
            @endunless
            @if($colorVal)
              <label class="checkline">
                <input type="checkbox" name="clear_color[]" value="{{ $s->key }}">
                回退默认（清除自定义颜色）
              </label>
            @endif
          </div>
        @elseif($s->type==='image')
          @php($imgSize = match($s->key){ 'seo_og_image' => '社交分享图建议 1200×630（1.91:1）', 'geo_org_logo' => 'Logo 建议 512×512 以上方形 PNG', 'contact_wechat_qr' => '微信二维码建议正方形 600×600 以上 PNG', default => '建议使用清晰的 jpg/png/webp' })
          <input type="text" name="{{ $s->key }}" value="{{ old($s->key, $s->value) }}" list="media-paths" placeholder="路径或 URL，可从列表选择或直接上传">
          <datalist id="media-paths">
            @foreach($images as $img)<option value="{{ $img->url() }}">{{ $img->alt ?: $img->original_name }}</option>@endforeach
          </datalist>
          <input type="file" name="file_{{ $s->key }}" class="file-input mt-2" accept="image/*">
          <div class="help mt-1">{{ $imgSize }}，≤8MB</div>
          @if($s->value)<div class="small mt-2"><img src="{{ asset($s->value) }}" alt="" class="img-preview"></div>@endif
        @else
          <input type="text" name="{{ $s->key }}" value="{{ old($s->key, $s->value) }}">
        @endif

        @if($s->key==='sync_geoflow_token')
          <div class="small mono token-box mt-2">{{ $s->value ?: '尚未生成' }}</div>
        @endif
      </div>
    @endforeach

    <div class="form-actions">
      <button class="btn btn-primary">保存设置</button>
      @if($group==='sync')
        <form method="post" action="{{ route('admin.settings.token') }}" class="form-inline"
              onsubmit="return confirm('重新生成后旧 Token 立即失效，确认？')">@csrf
          <button class="btn">重新生成 Token</button>
        </form>
      @endif
    </div>
  </form>
</div>
@endsection
