@extends('admin.layout')
@section('title','产出预览')
@section('page-desc','以下为实时生成内容，与线上文件保持一致。')

@section('content')
<div class="card">
  <h2>{{ ['sitemap'=>'sitemap.xml','llms'=>'llms.txt','robots'=>'robots.txt','rss'=>'feed.xml'][$kind] }} 预览
    <span class="hint">实时生成</span>
  </h2>
  <pre class="preview">{{ $content }}</pre>
  <p class="mt-3 mb-0"><a class="btn" href="{{ route('admin.geo.tools') }}">返回抓取产出与对接</a></p>
</div>
@endsection
