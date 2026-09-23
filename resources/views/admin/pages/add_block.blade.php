@extends('admin.layout')
@section('title','添加区块')
@section('page-desc','选择要放入槽位的区块类型；仅列出该模板槽位允许的类型。添加后进入内容编辑。')

@section('content')
@php
  $currentSlot = request('slot') ?: $template->slotNames()[0];
  $allowed = [];
  foreach ($blockTypes as $tk => $td) {
      if ($template->allows($currentSlot, $tk) && $td->allows($page->template, $currentSlot)) {
          $allowed[$tk] = $td;
      }
  }
@endphp

<form method="post" class="card" action="{{ route('admin.pages.storeBlock', $page) }}">
  @csrf
  <input type="hidden" name="slot" value="{{ $currentSlot }}">

  <div class="card-head"><h2>目标槽位</h2></div>
  <div class="actions">
    @foreach($template->slotNames() as $sn)
      <a class="btn btn-sm {{ $currentSlot === $sn ? 'btn-primary' : '' }}"
         href="{{ route('admin.pages.addBlock', ['page' => $page, 'slot' => $sn]) }}">
        {{ $template->slot($sn)['label'] ?? $sn }}
      </a>
    @endforeach
  </div>

  <table class="tbl mt-2">
    <thead>
      <tr>
        <th class="w-60"></th>
        <th class="w-240">区块类型</th>
        <th>说明</th>
      </tr>
    </thead>
    <tbody>
    @forelse($allowed as $tk => $td)
      <tr>
        <td><input type="radio" name="type" value="{{ $tk }}" required></td>
        <td><strong>{{ $td->label }}</strong> <span class="hint mono">{{ $tk }}</span></td>
        <td class="small">{{ $td->help ?: '—' }}</td>
      </tr>
    @empty
      <tr><td colspan="3" class="empty-cell">该槽位没有可添加的区块类型。</td></tr>
    @endforelse
    </tbody>
  </table>

  <div class="form-actions">
    <button type="submit" class="btn btn-sm btn-primary">添加并编辑内容</button>
    <a class="btn btn-sm" href="{{ route('admin.pages.composer', $page) }}">取消</a>
  </div>
</form>
@endsection
