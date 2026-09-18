@extends('layouts.site')

@section('content')

<div class="page-head">
  <div class="wrap">
    <h1>站内搜索</h1>
    <p>检索产品、知识与常见问题，快速找到你需要的信息。</p>
  </div>
</div>

<div class="wrap" style="padding-top:26px;padding-bottom:40px">
  <form action="{{ url('/search') }}" method="get" style="display:flex;gap:12px;max-width:600px;margin-bottom:30px">
    <input type="search" name="q" value="{{ $q }}" placeholder="输入关键词，如「Sample SnackSample Marinade」「OEM 代工」"
           style="flex:1;padding:13px 16px;border:1.5px solid var(--line);border-radius:var(--radius-sm);
                  font-size:15px;background:var(--surface);color:var(--ink);font-family:inherit">
    <button class="btn" type="submit">搜索</button>
  </form>

  @if($q === '')
    <p style="color:var(--ink-muted)">请输入至少 2 个字的关键词。</p>
  @elseif($items && $items->count())
    <p style="font-size:14px;color:var(--ink-muted);margin-bottom:18px">
      找到 {{ $items->total() }} 条与「{{ $q }}」相关的内容
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
      <p style="margin:0 0 12px">没有找到与「{{ $q }}」相关的内容</p>
      <p style="margin:0;font-size:14px">
        换个关键词试试，或直接
        @if(!empty($siteSettings['contact_phone']))
          致电 <strong style="color:var(--brand)">{{ $siteSettings['contact_phone'] }}</strong>
        @else
          <a href="{{ url('/contact/contact-us') }}">联系我们</a>
        @endif
      </p>
    </div>
  @endif
</div>

@endsection
