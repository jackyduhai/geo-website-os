{{-- 二级导航：产品 / 知识 / 关于三类页面使用。期望 $subnav=['items'=>[['name','slug','url','on'?,'external'?]],'active'=>slug|null] --}}
@if(!empty($subnav['items']))
<div class="subnav">
  <div class="wrap subnav-in" role="tablist" aria-label="{{ __('ui.subnav_aria') }}">
    @foreach($subnav['items'] as $it)
      @php
        $isOn = array_key_exists('on', $it)
          ? (bool) $it['on']
          : (($it['slug'] ?? null) === ($subnav['active'] ?? null));
      @endphp
      <a href="{{ $it['url'] }}" class="{{ $isOn ? 'on' : '' }}" role="tab"
         @if($isOn) aria-current="page" aria-selected="true" @else aria-selected="false" @endif
         @if(!empty($it['external'])) target="_blank" rel="noopener" @endif>{{ $it['name'] }}</a>
    @endforeach
  </div>
</div>
@endif
