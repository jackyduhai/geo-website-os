{{-- 前台语言切换器（P-STEP 18F）：仅当站点启用多个语言时渲染。
     语言中立路径 = 当前路径去掉语言前缀；切语言只换前缀、保留路径与查询串。 --}}
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

  // 语言中立路径（以 / 开头，保留尾斜杠）。
  $neutral = '/' . ltrim(request()->path(), '/');
  $enPrefix = LocaleRegistry::prefix('en');
  if ($enPrefix !== '' && str_starts_with($neutral, '/' . $enPrefix . '/')) {
      $neutral = substr($neutral, strlen('/' . $enPrefix));
  } elseif ($enPrefix !== '' && $neutral === '/' . $enPrefix) {
      $neutral = '/';
  }
  $neutral = '/' . ltrim($neutral, '/');

  $queryString = request()->getQueryString();
  $suffix = $queryString !== null && $queryString !== '' ? '?' . $queryString : '';

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
        $href = $pre !== '' ? '/' . $pre . $neutral : $neutral;
        if ($href === '') { $href = '/'; }
      @endphp
      @if($loc === $cur)
        <span class="ls-cur" aria-current="true">{{ $labels[$loc] ?? $loc }}</span>
      @else
        <a class="ls-link" href="{{ url($href) . $suffix }}" hreflang="{{ $loc }}">{{ $labels[$loc] ?? $loc }}</a>
      @endif
    @endforeach
  </div>
@endif
