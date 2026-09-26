{{-- 系统块 download_panel：产品详情页「技术资料下载」区块（18R-2b）。
     数据由 EntityRenderContext（product 分支）经 $downloads 注入：
     每个元素含 name/type/language/version/url。无资料时整块不渲染。
     纯展示块：无 <script>、不改 Schema/SEO；下载链接经 SafeUrl。 --}}
@if(!empty($downloads))
<section class="sec sec-tint" {!! $semanticAttrs ?? '' !!}>
  <div class="wrap-narrow">
    <div class="sec-head">
      <span class="eyebrow">DOWNLOADS</span>
      <h2 class="sec-h">{{ __('ui.downloads_h2') }}</h2>
    </div>
    <div class="pain-grid">
      @foreach($downloads as $d)
        <a class="pain-card" href="{{ \App\Support\SafeUrl::sanitize($d['url'], '#') }}"
           @if(!empty($d['url'])) target="_blank" rel="noopener nofollow" @endif>
          <h3>{{ $d['name'] }}</h3>
          <p>
            @if(!empty($d['type']))<span class="prod-tag">{{ $d['type'] }}</span>@endif
            @if(!empty($d['language']))<span class="prod-tag">{{ $d['language'] }}</span>@endif
            @if(!empty($d['version']))<span class="prod-tag">v{{ $d['version'] }}</span>@endif
          </p>
        </a>
      @endforeach
    </div>
  </div>
</section>
@endif
