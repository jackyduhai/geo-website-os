@extends('layouts.site')

@section('content')

<div class="page-head">
  <div class="wrap">
    <h1>{{ $category->displayName() }}</h1>
    @if($category->description)
      <p>{{ $category->description }}</p>
    @endif
  </div>
</div>

{{-- 子栏目（如产品中心下的五条产品线） --}}
@if(($children ?? false) && $children->isNotEmpty())
  <section class="sec" style="padding-top:44px">
    <div class="wrap">
      <div class="grid g3">
        @foreach($children as $c)
          <a class="pcard" href="{{ $c->url() }}">
            <span class="pcard-h">
              <span class="feat-ic">@if(!empty($c->icon))@include('site._icon', ['name' => $c->icon])@else@include('site._product_icon', ['slug' => $c->slug])@endif</span>
              <h3>{{ $c->displayName() }}</h3>
            </span>
            <p>{{ $c->description }}</p>
            <span class="go">{{ __('ui.cat_view_line') }} <span class="arr">→</span></span>
          </a>
        @endforeach
      </div>
    </div>
  </section>
@endif

@if(($groups ?? false) && $groups->isNotEmpty())
  <div class="wrap" style="margin-top:8px">
    <a class="tag {{ !request('group') ? 'tag-a' : '' }}" href="{{ $category->url() }}">{{ __('ui.cat_all') }}</a>
    @foreach($groups as $g)
      <a class="tag {{ (int)request('group') === $g->id ? 'tag-a' : '' }}"
         href="{{ $category->url() }}?group={{ $g->id }}">{{ $g->name }}</a>
    @endforeach
  </div>
@endif

{{-- 纯父栏目（只有子栏目、自身无内容）不再渲染空列表 --}}
@unless(($children ?? false) && $children->isNotEmpty() && $items->isEmpty())
<section class="sec" style="padding-top:28px">
  <div class="wrap">
    @if($items->count())
      @if($category->isProductList())
        {{-- 产品叶子栏目：系列内容用与产品中心一致的富卡片 --}}
        <div class="grid g3">
          @foreach($items as $p)
            <a class="pcard" href="{{ $p->url() }}">
              <span class="pcard-h">
                <span class="feat-ic">@if(!empty($category->icon))@include('site._icon', ['name' => $category->icon])@else@include('site._product_icon', ['slug' => $category->slug])@endif</span>
                <h3>{{ $p->title }}</h3>
              </span>
              @if($p->summary)<p>{{ $p->summary }}</p>@endif
              <span class="go">{{ __('ui.cat_view_product') }} <span class="arr">→</span></span>
            </a>
          @endforeach
        </div>
      @else
        <div class="posts">
          @foreach($items as $p)
            <a class="post" href="{{ $p->url() }}">
              <h2>{{ $p->title }}</h2>
              @if($p->summary)<p>{{ $p->summary }}</p>@endif
              @if($p->published_at)<time>{{ $p->published_at->format('Y-m-d') }}</time>@endif
            </a>
          @endforeach
        </div>
      @endif
      <div style="margin-top:28px">{{ $items->links('pagination::bootstrap-5') }}</div>
    @else
      <div class="card" style="text-align:center;padding:48px 20px;color:var(--ink-muted)">
        <p style="margin:0 0 14px">{{ __('ui.cat_empty') }}</p>
        @if(!empty($siteSettings['contact_phone']))
          <p style="margin:0">{{ __('ui.cat_hotline') }}<strong style="color:var(--brand)">{{ $siteSettings['contact_phone'] }}</strong></p>
        @endif
      </div>
    @endif
  </div>
</section>
@endunless

@endsection
