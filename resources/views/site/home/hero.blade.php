{{-- S01 首屏（装修区块 hero，可运营双模式）：
     A · 左价值主张 / 右真实参数卡（默认，信息型）；
     B · 图片 Banner 轮播（读取“展示→Banner 轮播”里首页顶部且启用、有图的条目，多图轮播，SSR 全量输出）。
     B 模式若无可用 Banner，自动回退 A，绝不留白。整页始终只有一个 H1（B 模式取第一张主标题）。 --}}
@php
  $siteName = $siteSettings['site_name'] ?? config('app.name');
  $blkCfg = $blk->cfg();
  $heroMode = $blkCfg['mode'] ?? 'A';
  $heroAutoplay = ! empty($blkCfg['autoplay']);

  // 默认口径全部由通用 Facts 数据派生，不在此写死任何具体企业 / 行业信息
  $company = $company ?? \App\Support\Facts::company();
  $heroWsCount = count(\App\Support\Facts::workshops());
  $heroRegionCount = count(\App\Support\Facts::salesRegions());
  $heroYears = (int) ($company['tech_experience_years'] ?? 0);
  $heroCustomers = implode('、', array_slice($company['target_customers'] ?? [], 0, 3));
  $heroBrand = $company['brand'] ?? ($company['name'] ?? config('app.name'));
  $heroCapacity = number_format((int) ($company['annual_capacity_tons'] ?? 0));
  $defaultKicker = $heroBrand . ($heroYears > 0 ? ' · 深耕行业 ' . $heroYears . ' 年' : ' · 源头制造工厂');

  $defaultTitle = $heroBrand . '，从研发到量产的一站式定制制造';
  $heroTitle = $blk->title ?? null;
  if (blank($heroTitle) || trim((string) $heroTitle) === trim((string) $siteName)) {
      $heroTitle = $defaultTitle;
  }
  // A/C 共用说明正文：后台「首页装修」可编辑，留空回退由 Facts 派生的默认口径
  $defaultLead = '自有约 ' . number_format((int) ($company['area_sqm'] ?? 0)) . ' ㎡ 厂区、' . $heroWsCount . ' 个生产车间，年产能约 ' . $heroCapacity . ' 吨。为' . $heroCustomers . '等客户提供定制研发、OEM / ODM 代工与稳定供货，销售覆盖全国 ' . $heroRegionCount . ' 大区域。';
  $heroLead = trim((string) ($blkCfg['lead'] ?? '')) !== '' ? trim((string) $blkCfg['lead']) : $defaultLead;

  // B 模式可用 Banner：必须有图；控制器已按启用 + 投放位置=home_top 过滤
  $hbList = collect($banners ?? [])->filter(fn ($b) => $b->imageUrl())->values();
  $useBanner = $heroMode === 'B' && $hbList->isNotEmpty();
  // C 模式：左价值主张（与 A 同源、可后台编辑）+ 右侧首屏 Banner 图（图文混排，类似成熟 SaaS 首屏）
  $useSplit = $heroMode === 'C' && $hbList->isNotEmpty();
  if ($useSplit) {
      $sb = $hbList->first();
      $sbAlt = trim((string) ($sb->title ?? '')) !== '' ? trim((string) $sb->title) : $heroTitle;
  }
  $navPhone = config('copy.nav.phone');
  $navPhoneTel = config('copy.nav.phoneTel');
@endphp

@if($useBanner)
  {{-- ============================ B · 图片 Banner 轮播 ============================ --}}
  @php $hbCount = $hbList->count(); $hbId = 'hbanner'; @endphp
  <section class="hb" id="top"
           data-autoplay="{{ $heroAutoplay ? '1' : '0' }}"
           aria-label="首屏轮播">
    <div class="hb-track" id="{{ $hbId }}">
      @foreach($hbList as $i => $b)
        @php
          $rawTitle = trim((string) ($b->title ?? ''));
          $bSub = trim((string) ($b->subtitle ?? ''));
          // 只有后台填了主标题或副标题，才在图上压字；图片自带文字时两者留空 → 出干净大图，避免叠字
          $hasOverlay = $rawTitle !== '' || $bSub !== '';
          $visibleTitle = $rawTitle !== '' ? $rawTitle : ($i === 0 ? $heroTitle : '');
          $altText = $rawTitle !== '' ? $rawTitle : $heroTitle;
          $bLink = trim((string) ($b->link ?? ''));
          $bLinkText = trim((string) ($b->link_text ?? '')) ?: '了解详情';
          $bBlank = (int) ($b->target ?? 0) === 1;
          $href = $bLink !== '' ? (preg_match('~^(https?:|tel:|/#|/)~', $bLink) ? (str_starts_with($bLink, '/') ? url($bLink) : $bLink) : url('/' . ltrim($bLink, '/'))) : null;
        @endphp
        <div class="hb-slide" id="{{ $hbId }}-{{ $i }}">
          @if($href && ! $hasOverlay)
            <a class="hb-imglink" href="{{ $href }}" @if($bBlank) target="_blank" rel="noopener" @endif aria-label="{{ $altText }}">
              @if($i === 0)
                <x-picture :src="$b->imageUrl()" :alt="$altText" loading="eager" fetchpriority="high" />
              @else
                <x-picture :src="$b->imageUrl()" :alt="$altText" loading="lazy" />
              @endif
            </a>
          @else
            @if($i === 0)
              <x-picture :src="$b->imageUrl()" :alt="$altText" loading="eager" fetchpriority="high" />
            @else
              <x-picture :src="$b->imageUrl()" :alt="$altText" loading="lazy" />
            @endif
          @endif

          @if($hasOverlay)
            <div class="hb-overlay">
              <div class="hb-inner">
                @if($i === 0)
                  <h1 class="hb-title">{{ $visibleTitle }}</h1>
                @elseif($visibleTitle !== '')
                  <div class="hb-title">{{ $visibleTitle }}</div>
                @endif
                @if($bSub !== '')<p class="hb-sub">{{ $bSub }}</p>@endif
                @if($href)
                  <div class="hb-actions">
                    <a class="btn btn-primary btn-lg" href="{{ $href }}" @if($bBlank) target="_blank" rel="noopener" @endif>
                      {{ $bLinkText }}<span class="arr">→</span>
                    </a>
                    <a class="btn btn-ghost btn-lg" href="{{ url('/cooperation/') }}">获取定制方案</a>
                  </div>
                @endif
              </div>
            </div>
          @elseif($i === 0)
            {{-- 图片自带文字、不在图上压字：首图保留唯一、仅供读屏与 SEO/GEO 的 H1 --}}
            <h1 class="sr-only">{{ $heroTitle }}</h1>
          @endif
        </div>
      @endforeach
    </div>
    @if($hbCount > 1)
      <div class="hb-dots" role="tablist" aria-label="轮播切换">
        @foreach($hbList as $i => $b)
          <button type="button" class="hb-dot @if($i===0) on @endif" data-target="{{ $i }}"
                  role="tab" aria-label="第 {{ $i + 1 }} 张"></button>
        @endforeach
      </div>
    @endif
  </section>
  @if($hbCount > 1)
  <script nonce="{{ $cspNonce ?? '' }}">
  (function(){
    var root=document.querySelector('.hb'); if(!root) return;
    var track=root.querySelector('.hb-track'), slides=track.children, dots=root.querySelectorAll('.hb-dot'), n=slides.length;
    var idx=0,timer=null;
    function go(i){ idx=(i+n)%n; track.scrollTo({left:slides[idx].offsetLeft,behavior:'smooth'}); }
    function sync(){ var i=Math.round(track.scrollLeft/track.clientWidth); if(i!==idx){idx=i;} dots.forEach(function(d,k){d.classList.toggle('on',k===idx);}); }
    dots.forEach(function(d){ d.addEventListener('click',function(){ go(parseInt(d.dataset.target,10)); stop(); }); });
    track.addEventListener('scroll',sync,{passive:true});
    function start(){
      if(root.dataset.autoplay!=='1') return;
      if(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
      stop(); timer=setInterval(function(){go(idx+1);},5000);
    }
    function stop(){ if(timer){clearInterval(timer);timer=null;} }
    root.addEventListener('mouseenter',stop); root.addEventListener('mouseleave',start);
    start();
  })();
  </script>
  @endif
@elseif($useSplit)
  {{-- ============================ C · 一体化主视觉：一张图=一个主题，图文按钮一起交叉淡入 ============================ --}}
  @php
    $cCount = $hbList->count();
    $defaultCta = $ctaText ?? (config('copy.nav.cta') ?? '免费获取样品');
    // 逐幻灯解析：标题/正文/按钮各自独立，留空回退默认口径（整页唯一 H1 取第一张）
    $cSlides = $hbList->map(function ($cb, $ci) use ($heroTitle, $heroLead, $defaultCta) {
        $t = trim((string) ($cb->title ?? ''));
        $l = trim((string) ($cb->subtitle ?? ''));
        $rawLink = trim((string) ($cb->link ?? ''));
        $href = $rawLink !== ''
            ? (preg_match('~^(https?:|tel:|/#|/)~', $rawLink) ? (str_starts_with($rawLink, '/') ? url($rawLink) : $rawLink) : url('/' . ltrim($rawLink, '/')))
            : url('/') . '#s08';
        return [
            'title'   => $t !== '' ? $t : $heroTitle,
            'lead'    => $l !== '' ? $l : $heroLead,
            'href'    => $href,
            'cta'     => trim((string) ($cb->link_text ?? '')) !== '' ? trim((string) $cb->link_text) : $defaultCta,
            'alt'     => $t !== '' ? $t : $heroTitle,
        ];
    })->values();
  @endphp
  <section class="hero-int reveal in" id="top" data-autoplay="{{ $heroAutoplay ? '1' : '0' }}" aria-label="首屏">
    <div class="hi-panel">
      {{-- 背景图层叠放，交叉淡入；羽化 mask 加在容器上，避免逐张羽化抖动 --}}
      <div class="hi-bgstack" aria-hidden="true">
        @foreach($hbList as $ci => $cb)
          @php
            $hiLoading = $ci === 0 ? 'eager' : 'lazy';
            $hiFetch   = $ci === 0 ? 'high' : null; // 非首图不传 fetchpriority
          @endphp
          <x-picture :src="$cb->imageUrl()" :alt="$cSlides[$ci]['alt']"
                     :img-class="'hi-bg ' . ($ci === 0 ? 'on' : '')"
                     :loading="$hiLoading" :fetchpriority="$hiFetch" decoding="async" />
        @endforeach
      </div>
      <span class="hi-glow" aria-hidden="true"></span>
      <span class="hi-veil" aria-hidden="true"></span>
      <div class="wrap hi-in">
        <div class="hi-copy">
          <span class="hi-kicker">{{ $blk->subtitle ?: $defaultKicker }}</span>
          {{-- 逐幻灯文案层（grid 堆叠，同 index 与背景一起淡入；首图 H1，其余 div 保证整页唯一 H1） --}}
          <div class="hi-copystack">
            @foreach($cSlides as $ci => $cs)
              <div class="hi-copy-layer @if($ci === 0) on @endif" @if($ci > 0) aria-hidden="true" @endif>
                @if($ci === 0)
                  <h1 class="hi-title">{{ $cs['title'] }}</h1>
                @else
                  <div class="hi-title">{{ $cs['title'] }}</div>
                @endif
                <p class="hi-lead">{{ $cs['lead'] }}</p>
                <div class="hi-actions">
                  <a class="btn btn-primary btn-lg" href="{{ $cs['href'] }}">{{ $cs['cta'] }}<span class="arr">→</span></a>
                  <a class="btn hi-ghost btn-lg" href="{{ url('/cooperation/') }}">获取定制方案</a>
                  @if(!empty($navPhone))
                    <a class="hi-tel" href="tel:{{ $navPhoneTel }}">致电 {{ $navPhone }}</a>
                  @endif
                </div>
              </div>
            @endforeach
          </div>
          <ul class="hi-trust">
            <li>@include('site._icon', ['name' => 'clock', 'size' => 16]) {{ $heroYears > 0 ? '深耕行业 ' . $heroYears . ' 年' : '多年行业经验' }}</li>
            <li>@include('site._icon', ['name' => 'sliders', 'size' => 16]) 定向研发 · 快速打样</li>
            <li>@include('site._icon', ['name' => 'factory', 'size' => 16]) {{ $heroWsCount }} 个车间 · 年产能约 {{ $heroCapacity }} 吨</li>
          </ul>
        </div>
      </div>
      @if($cCount > 1)
        {{-- 多张：图文一起交叉淡入（圆点手动切换；自动播放由后台 autoplay 开关控制，默认关） --}}
        <div class="hi-dots" role="tablist" aria-label="首屏幻灯切换">
          @foreach($hbList as $ci => $cb)
            <button type="button" class="hi-dot @if($ci === 0) on @endif" data-i="{{ $ci }}"
                    role="tab" aria-label="第 {{ $ci + 1 }} 张"></button>
          @endforeach
        </div>
        <script nonce="{{ $cspNonce ?? '' }}">
        (function(){
          var root=document.querySelector('.hero-int'); if(!root) return;
          var imgs=root.querySelectorAll('.hi-bg'), layers=root.querySelectorAll('.hi-copy-layer'),
              dots=root.querySelectorAll('.hi-dot'), n=imgs.length; if(n<2) return;
          var panel=root.querySelector('.hi-panel'), idx=0, timer=null;
          function go(i){ idx=(i+n)%n;
            imgs.forEach(function(im,k){im.classList.toggle('on',k===idx);});
            layers.forEach(function(ly,k){ly.classList.toggle('on',k===idx);});
            dots.forEach(function(d,k){d.classList.toggle('on',k===idx);}); }
          dots.forEach(function(d){ d.addEventListener('click',function(){ go(parseInt(d.dataset.i,10)); stop(); }); });
          function start(){
            if(root.dataset.autoplay!=='1') return;
            if(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            stop(); timer=setInterval(function(){go(idx+1);},5500);
          }
          function stop(){ if(timer){clearInterval(timer);timer=null;} }
          panel.addEventListener('mouseenter',stop); panel.addEventListener('mouseleave',start);
          start();
        })();
        </script>
      @endif
    </div>
  </section>
@else
  {{-- ============================ A · 价值主张 + 参数卡（默认） ============================ --}}
  @php $lines = $productLines ?? []; @endphp
  <section class="hero-split reveal in" id="top">
    <div class="wrap hero-in">
      <div class="hero-copy">
        <span class="hero-kicker-line">{{ $blk->subtitle ?: $defaultKicker }}</span>
        <h1 class="hero-title">{{ $heroTitle }}</h1>
        <p class="hero-lead">{{ $heroLead }}</p>
        <div class="actions">
          <a class="btn btn-primary btn-lg" href="{{ url('/') }}#s08">{{ $ctaText ?? (config('copy.nav.cta') ?? '免费获取样品') }}<span class="arr">→</span></a>
          <a class="btn btn-secondary btn-lg" href="{{ url('/cooperation/') }}">获取定制方案</a>
          @if(!empty($navPhone))
            <a class="btn-text btn-lg" href="tel:{{ $navPhoneTel }}">致电 {{ $navPhone }}</a>
          @endif
        </div>
        <ul class="hero-trust">
          <li>@include('site._icon', ['name' => 'clock', 'size' => 16]) {{ $heroYears > 0 ? '深耕行业 ' . $heroYears . ' 年' : '多年行业经验' }}</li>
          <li>@include('site._icon', ['name' => 'sliders', 'size' => 16]) 定向研发 · 快速打样</li>
          <li>@include('site._icon', ['name' => 'factory', 'size' => 16]) {{ $heroWsCount }} 个车间 · 年产能约 {{ $heroCapacity }} 吨</li>
        </ul>
      </div>
      <div class="hero-visual">
        @php $pcName = $heroProduct['short_name'] ?? ($heroProduct['name'] ?? ''); @endphp
        <div class="param-card" aria-label="标准化参数示例{{ $pcName !== '' ? '：' . $pcName : '' }}">
          <div class="pc-head">
            <strong>标准化参数示例{{ $pcName !== '' ? ' · ' . $pcName : '' }}</strong>
            <span>工艺参数，产线可直接复现</span>
          </div>
          <dl class="pc-body">
            @forelse($heroParams ?? [] as $hp)
              <div class="pc-row"><dt>{{ $hp['label'] }}</dt><dd>{{ $hp['value'] }}</dd></div>
            @empty
              <div class="pc-row"><dt>参数</dt><dd>详见对应产品规格表</dd></div>
            @endforelse
          </dl>
          <div class="pc-foot">
            <div class="pc-lines">
              @foreach($lines as $line)
                <em>{{ is_array($line) ? ($line['name'] ?? '') : $line->name }}</em>
              @endforeach
            </div>
            <a href="{{ url('/solutions/') }}">按应用场景选产品 <span class="arr">→</span></a>
          </div>
        </div>
      </div>
    </div>
  </section>
@endif
