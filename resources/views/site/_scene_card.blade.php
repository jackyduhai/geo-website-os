{{--
  SceneCard（Catalog 站点目录读模型驱动）：3px 强调色顶条；hover 上移 + 轻阴影 + 展开真实工艺 / 参数；
  移动端默认展开（CSS），揭示内容必须在初始 DOM。入参：$sc（Catalog 场景）。
--}}
@php
  $comboNames = [];
  foreach (($sc['combo'] ?? []) as $pslug) {
      $prod = \App\Support\Catalog::product($pslug);
      if ($prod) $comboNames[] = $prod['short_name'] ?? $prod['name'];
  }
  $reveal = $sc['hover_reveal'] ?? ($sc['key_param_display'] ?? '');
  $ctaText = ($fullCta ?? false) ? '查看完整组合与参数' : '看看这类场景用什么';
@endphp
<a class="scene-card" href="{{ url('/solutions/' . $sc['slug'] . '/') }}">
  <span class="sc-top" aria-hidden="true"></span>
  <span class="sc-name">{{ $sc['name'] }}</span>
  <span class="sc-intro">{{ $sc['desc'] }}</span>
  @if($comboNames)
    <span class="sc-combo">
      @foreach($comboNames as $cn)<em>{{ $cn }}</em>@endforeach
    </span>
  @endif
  <span class="sc-reveal">
    @if($reveal)
      <span class="scp"><i>{{ $reveal }}</i></span>
    @endif
    <span class="sc-go">{{ $ctaText }} <span class="arr" aria-hidden="true">→</span></span>
  </span>
</a>
