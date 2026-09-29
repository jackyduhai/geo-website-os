{{-- 前台语言切换器（P-STEP 18F）：仅当站点启用多个语言时渲染。
     语言中立路径 = 当前路径去掉语言前缀；切语言只换前缀、保留路径与查询串。
     直接拼接绝对 URL，绕过 GeoUrlGenerator 的 locale 前缀兜底——否则中文链接
     在 /en 请求中会被错误加回 /en（url('/') → /en），导致点击切不回中文。 --}}
@php
  use App\Models\Setting;
  use App\Support\Localization\LocaleContext;
  use App\Support\Localization\LocaleRegistry;

  $rawLocales = Setting::get('site_supported_locales', [LocaleRegistry::default()]);
  if (is_array($rawLocales)) {
      $siteLocales = array_values(array_filter(array_map(
          fn ($v) => is_string($v) ? trim($v) : '',
          $rawLocales
      )));
  } else {
      $siteLocales = array_values(array_filter(array_map('trim', explode(',', (string) $rawLocales))));
  }
  if (empty($siteLocales)) { $siteLocales = [LocaleRegistry::default()]; }

  $cur = LocaleContext::current();

  // 语言中立路径（以 / 开头）。
  $neutral = '/' . ltrim(request()->path(), '/');
  $enPrefix = LocaleRegistry::prefix('en');
  if ($enPrefix !== '' && str_starts_with($neutral, '/' . $enPrefix . '/')) {
      $neutral = substr($neutral, strlen('/' . $enPrefix));
  } elseif ($enPrefix !== '' && $neutral === '/' . $enPrefix) {
      $neutral = '/';
  }
  $neutral = '/' . ltrim($neutral, '/');

  // 原始路径是否为目录型（以 / 结尾，含首页），切换语言时保留尾斜杠。
  $rawPath = (string) parse_url((string) request()->getRequestUri(), PHP_URL_PATH);
  $keepSlash = str_ends_with($rawPath, '/');

  // 查询串原样保留。
  $queryString = request()->getQueryString();
  $querySuffix = $queryString !== null && $queryString !== '' ? '?' . $queryString : '';

  $origin = request()->getSchemeAndHttpHost();

  $labels = [
      'zh-CN' => __('ui.locale_zh'),
      'en'    => __('ui.locale_en'),
  ];
@endphp

@if(count($siteLocales) > 1)
  <div class="locale-switch" role="group" aria-label="{{ __('ui.locale_switch_aria') }}">
    @foreach($siteLocales as $loc)
      @php
        $pre = LocaleRegistry::prefix($loc);
        $localPath = ($pre !== '' ? '/' . $pre : '') . $neutral;
        if ($keepSlash && ! str_ends_with($localPath, '/')) {
            $localPath .= '/';
        }
        $absolute = $origin . $localPath;
      @endphp
      @if($loc === $cur)
        <span class="ls-cur" aria-current="true">{{ $labels[$loc] ?? $loc }}</span>
      @else
        <a class="ls-link" href="{{ $absolute . $querySuffix }}" hreflang="{{ $loc }}">{{ $labels[$loc] ?? $loc }}</a>
      @endif
    @endforeach
  </div>
@endif
