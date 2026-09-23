@extends('layouts.site')

@section('content')

<div class="page-head">
  <div class="wrap">
    <h1>{{ __('ui.search_h1') }}</h1>
    <p>{{ __('ui.search_lead') }}</p>
  </div>
</div>

<div class="wrap" style="padding-top:26px;padding-bottom:40px">
  <form action="{{ url('/search') }}" method="get" style="display:flex;gap:12px;max-width:600px;margin-bottom:30px">
    <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('ui.search_ph') }}"
           style="flex:1;padding:13px 16px;border:1.5px solid var(--line);border-radius:var(--radius-sm);
                  font-size:15px;background:var(--surface);color:var(--ink);font-family:inherit">
    <button class="btn" type="submit">{{ __('ui.search_btn') }}</button>
  </form>

  @if($q === '')
    <p style="color:var(--ink-muted)">{{ __('ui.search_min') }}</p>
  @elseif($items && $items->count())
    <p style="font-size:14px;color:var(--ink-muted);margin-bottom:18px">
      {{ __('ui.search_found', ['num' => $items->total(), 'q' => $q]) }}
    </p>
    <div class="posts">
      @foreach($items as $p)
        <a class="post" href="{{ $p->url() }}">
          <h2>{{ $p->title }}</h2>
          @if($p->summary)<p>{{ $p->summary }}</p>@endif
        </a>
      @endforeach
    </div>
    <div style="margin-top:28px">{{ $items->links('pagination::bootstrap-5') }}</div>
  @else
    <div class="card" style="padding:40px 22px;text-align:center;color:var(--ink-muted)">
      <p style="margin:0 0 12px">{{ __('ui.search_none', ['q' => $q]) }}</p>
      <p style="margin:0;font-size:14px">
        {{ __('ui.search_retry') }}
        @if(!empty($siteSettings['contact_phone']))
          ，或直接致电 <strong style="color:var(--brand)">{{ $siteSettings['contact_phone'] }}</strong>
        @elseif(!empty(\App\Support\Catalog::company()))
          ，或直接<a href="{{ url('/contact/') }}">{{ __('ui.contact_us') }}</a>
        @endif
      </p>
    </div>
  @endif
</div>

@endsection
