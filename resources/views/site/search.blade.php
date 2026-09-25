@extends('layouts.site')

@section('content')

<div class="page-head">
  <div class="wrap">
    <h1>{{ __('ui.search_h1') }}</h1>
    <p>{{ __('ui.search_lead') }}</p>
  </div>
</div>

<div class="wrap" style="padding-top:26px;padding-bottom:40px">
  <form action="{{ \App\Support\PublicUrl::url('search') }}" method="get" style="display:flex;gap:var(--sp-3);max-width:600px;margin-bottom:var(--sp-8)">
    <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('ui.search_ph') }}"
           style="flex:1;padding:var(--sp-3) var(--sp-4);border:1.5px solid var(--line);border-radius:var(--radius-sm);
                  font-size:var(--fs-sm);background:var(--surface);color:var(--ink);font-family:inherit">
    <button class="btn" type="submit">{{ __('ui.search_btn') }}</button>
  </form>

  @if($q === '')
    <p style="color:var(--ink-muted)">{{ __('ui.search_min') }}</p>
  @elseif($items && $items->count())
    <p style="font-size:var(--fs-xs);color:var(--ink-muted);margin-bottom:var(--sp-5)">
      {{ __('ui.search_found', ['num' => $items->total(), 'q' => $q]) }}
    </p>
    <div class="posts">
      @foreach($items as $p)
        <a class="post" href="{{ $p->url() }}">
          <h2>{!! \App\Support\Search\Highlighter::mark($p->title, $terms) !!}</h2>
          @if(trim((string) $p->snippet()) !== '')<p>{!! \App\Support\Search\Highlighter::mark($p->snippet(), $terms) !!}</p>@endif
        </a>
      @endforeach
    </div>
    <div style="margin-top:var(--sp-7)">{{ $items->links('pagination::bootstrap-5') }}</div>
  @else
    <div class="card" style="padding:var(--sp-10) var(--sp-6);text-align:center;color:var(--ink-muted)">
      <p style="margin:0 0 var(--sp-3)">{{ __('ui.search_none', ['q' => $q]) }}</p>
      <p style="margin:0;font-size:var(--fs-xs)">
        {{ __('ui.search_retry') }}
        @if(!empty($siteSettings['contact_phone']))
          {{ __('ui.search_or_call_prefix') }} <strong style="color:var(--brand)">{{ $siteSettings['contact_phone'] }}</strong>
        @elseif(!empty(\App\Support\Catalog::company()))
          {{ __('ui.search_or_contact_prefix') }}<a href="{{ \App\Support\PublicUrl::url('contact/') }}">{{ __('ui.contact_us') }}</a>
        @endif
      </p>
    </div>
  @endif
</div>

@endsection
