{{-- ParamTable：原生 table，仅水平分隔线、无竖线；数值列右对齐 tabular-nums；
     移动端用 td::before(data-label) 变键值卡，禁止横向滚动。
     变量：$rows=[['label','value','note'=>?]]；可选 $head=['参数项','用量 / 工艺']、$inverse、$caption --}}
@php
  $head = $head ?? ['参数项', '用量 / 工艺'];
  $inverse = $inverse ?? false;
@endphp
<div class="param-table-wrap">
  <table class="param-table {{ $inverse ? 'pt-inverse' : '' }}">
    @if(! empty($head))
      <thead><tr><th scope="col">{{ $head[0] }}</th><th scope="col" class="num">{{ $head[1] }}</th></tr></thead>
    @endif
    <tbody>
      @foreach($rows as $r)
        <tr>
          <th scope="row">{{ $r['label'] }}</th>
          <td class="num" data-label="{{ $r['label'] }}">
            {{ $r['value'] }}
            @if(! empty($r['note']))<span class="pt-note">{{ $r['note'] }}</span>@endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  @if(! empty($caption))<p class="tbl-note {{ $inverse ? 'muted' : '' }}">{{ $caption }}</p>@endif
</div>
