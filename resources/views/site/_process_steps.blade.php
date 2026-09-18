{{-- ProcessSteps：有序流程（ol，输出 HowTo 由控制器负责）。
     变量：$steps=[['name','desc']]（兼容 title/desc）；可选 $cols（默认按数量）、$small。 --}}
@php
  $cols = $cols ?? count($steps);
  $small = $small ?? false;
@endphp
<ol class="ps {{ $small ? 'ps-sm' : '' }}" style="--ps-cols:{{ max(1, (int) $cols) }};">
  @foreach($steps as $s)
    <li>
      <h4>{{ $s['name'] ?? ($s['title'] ?? '') }}</h4>
      <p>{{ $s['desc'] ?? ($s['text'] ?? '') }}</p>
    </li>
  @endforeach
</ol>
