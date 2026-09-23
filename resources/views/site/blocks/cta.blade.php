{{-- 通用组合 Block · CTA 转化带：标题 / 副标题 / 按钮（tint 控制浅色底）。 --}}
@php
  $c = $block->cfg();
  $caTitle = trim((string) ($c['title'] ?? ''));
  $caSub = trim((string) ($c['subtitle'] ?? ''));
  $caButtons = is_array($c['buttons'] ?? null) ? $c['buttons'] : [];
  $caTint = array_key_exists('tint', $c) ? ! empty($c['tint']) : true;
@endphp
@if($caTitle !== '' || $caSub !== '' || ! empty($caButtons))
  <section class="sec @if($caTint) sec-tint @endif">
    <div class="wrap">
      <div class="cta-band" style="text-align:center">
        @if($caTitle)<h2 style="font-size:28px;margin:0 0 10px">{{ $caTitle }}</h2>@endif
        @if($caSub)<p style="max-width:640px;margin:0 auto 22px">{{ $caSub }}</p>@endif
        @if(! empty($caButtons))
          <div class="cta-row" style="justify-content:center">
            @foreach($caButtons as $b)
              @php
                $bLabel = trim((string) ($b['label'] ?? ''));
                $bUrl = trim((string) ($b['url'] ?? ''));
                if ($bLabel === '' || $bUrl === '') { continue; }
                $bClass = match ($b['style'] ?? 'primary') {
                    'secondary' => 'btn-secondary',
                    'ghost' => 'btn-ghost',
                    default => 'btn-primary',
                };
              @endphp
              <a class="btn {{ $bClass }} btn-lg" href="{{ $bUrl }}">{{ $bLabel }}<span class="arr">→</span></a>
            @endforeach
          </div>
        @endif
      </div>
    </div>
  </section>
@endif
