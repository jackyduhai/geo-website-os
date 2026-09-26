@extends('admin.layout')
@section('title','模板对比')
@section('page-desc','切换前只读对比当前模板与目标模板的关键差异。对比不会修改任何数据；确认后才会激活目标模板并生成页面骨架，建议默认值仍需单独「初始化默认值」。')

@section('content')
@php
  $rows = [
    ['label' => '模板名称', 'cur' => $current['name'], 'tgt' => $target['name'], 'diff' => $current['name'] !== $target['name']],
    ['label' => '引用主题', 'cur' => $current['theme'], 'tgt' => $target['theme'], 'diff' => $current['theme'] !== $target['theme']],
    ['label' => '首页区块数', 'cur' => (string)$current['homeBlocks'], 'tgt' => (string)$target['homeBlocks'], 'diff' => $current['homeBlocks'] !== $target['homeBlocks']],
    ['label' => '配方数量', 'cur' => (string)$current['recipes'], 'tgt' => (string)$target['recipes'], 'diff' => $current['recipes'] !== $target['recipes']],
    ['label' => '默认菜单', 'cur' => $current['menus'] ? '含建议菜单' : '无', 'tgt' => $target['menus'] ? '含建议菜单' : '无', 'diff' => $current['menus'] !== $target['menus']],
    ['label' => '默认 SEO 条数', 'cur' => (string)$current['seo'], 'tgt' => (string)$target['seo'], 'diff' => $current['seo'] !== $target['seo']],
  ];
@endphp

<div class="card">
  <h2>「{{ $current['name'] }}」 → 「{{ $target['name'] }}」</h2>
  <table class="tbl">
    <thead><tr><th class="w-180">维度</th><th>当前：{{ $currentId !== '' ? $currentId : '核心模板' }}</th><th>目标：{{ $targetId }}</th></tr></thead>
    <tbody>
    @foreach($rows as $r)
      <tr @if($r['diff']) style="background:color-mix(in srgb,var(--warn,#d97706) 8%,transparent);" @endif>
        <td><strong>{{ $r['label'] }}</strong></td>
        <td class="small">{{ $r['cur'] }}</td>
        <td class="small">
          {{ $r['tgt'] }}
          @if($r['diff'])<span class="badge gap ml-1">变化</span>@endif
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  <p class="small empty-cell mt-2">提示：激活只生成 / 更新页面骨架，不会删除你的业务内容；产品、服务、文章等事实数据保持不变。建议默认设置 / 菜单 / SEO 不会自动覆盖，可在激活后单独执行「初始化默认值」。</p>
</div>

<div class="btn-row mt-2">
  <form method="post" action="{{ route('admin.templates.activate', $targetId) }}"
        onsubmit="return confirm('确认激活模板「{{ $target['name'] }}」并生成页面骨架？')">
    @csrf
    <button class="btn btn-primary">确认激活「{{ $target['name'] }}」</button>
  </form>
  <a class="btn" href="{{ route('admin.templates.index') }}">返回模板中心</a>
</div>
@endsection
