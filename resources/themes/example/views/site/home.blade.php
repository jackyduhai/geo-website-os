{{-- Example Theme · 首页：仅消费控制器注入数据（blocks/banners/seo/schemas），不查询引擎 --}}
@extends('layouts.site')

@section('content')
<article data-theme="example-home">
    <h1>{{ ($seo['og_site_name'] ?? '') ?: ($siteSettings['site_name'] ?? 'Website') }}</h1>
    <p>{{ $seo['description'] ?? '' }}</p>

    @if (! empty($homeFaqs))
    <section>
        <h2>FAQ</h2>
        @foreach ($homeFaqs as $faq)
        <details><summary>{{ $faq['title'] ?? ($faq['q'] ?? '') }}</summary>
            <p>{{ $faq['text'] ?? ($faq['a'] ?? '') }}</p>
        </details>
        @endforeach
    </section>
    @endif

    @if (! empty($knowledgeItems))
    <section>
        <h2>Knowledge</h2>
        <ul>
        @foreach ($knowledgeItems as $item)
        <li><a href="{{ $item->url() }}">{{ $item->title }}</a></li>
        @endforeach
        </ul>
    </section>
    @endif
</article>
@endsection
