@extends('admin.layout')
@section('title', $relation->exists ? '编辑实体关系' : '新建实体关系')
@section('page-desc','在同一站点的两个实体之间建立一条有向关系。下拉仅列出当前站点实体；关系是否对外可见取决于源、目标实体的发布状态。')

@section('content')
@php
  $grouped = $entities->groupBy('type');
  $oldFrom = (int) old('from_entity_id', $relation->from_entity_id ?: $preselectFrom);
  $oldTo   = (int) old('to_entity_id', $relation->to_entity_id ?: 0);
  $oldType = old('relation_type', $relation->relation_type ?: '');
  $oldSort = old('sort_order', $relation->sort_order ?? 0);
  $oldMeta = old('metadata_text', $relation->exists && is_array($relation->metadata) ? json_encode($relation->metadata, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : '');
@endphp

<form method="post" class="card narrow-md"
      action="{{ $relation->exists ? route('admin.relations.update',$relation) : route('admin.relations.store') }}">
  @csrf
  @if($relation->exists)@method('PUT')@endif

  <div class="form-grid">
    <div class="form-row">
      <label class="req">源实体（from）</label>
      <select name="from_entity_id" class="select" required>
        <option value="">请选择源实体…</option>
        @foreach($entityTypeNames as $tk=>$tn)
          @if(($grouped[$tk] ?? collect())->isNotEmpty())
            <optgroup label="{{ $tn }}">
              @foreach($grouped[$tk] as $opt)
                <option value="{{ $opt->id }}" @selected($oldFrom===$opt->id)>{{ $opt->name }}（{{ $opt->slug }}）</option>
              @endforeach
            </optgroup>
          @endif
        @endforeach
      </select>
      @error('from_entity_id')<div class="field-err">{{ $message }}</div>@enderror
    </div>

    <div class="form-row">
      <label class="req">关系类型</label>
      <select name="relation_type" class="select" required>
        <option value="">请选择关系类型…</option>
        @foreach($typeLabels as $rk=>$rl)
          <option value="{{ $rk }}" @selected($oldType===$rk)>{{ $rl }}</option>
        @endforeach
      </select>
      @error('relation_type')<div class="field-err">{{ $message }}</div>@enderror
    </div>

    <div class="form-row">
      <label class="req">目标实体（to）</label>
      <select name="to_entity_id" class="select" required>
        <option value="">请选择目标实体…</option>
        @foreach($entityTypeNames as $tk=>$tn)
          @if(($grouped[$tk] ?? collect())->isNotEmpty())
            <optgroup label="{{ $tn }}">
              @foreach($grouped[$tk] as $opt)
                <option value="{{ $opt->id }}" @selected($oldTo===$opt->id)>{{ $opt->name }}（{{ $opt->slug }}）</option>
              @endforeach
            </optgroup>
          @endif
        @endforeach
      </select>
      @error('to_entity_id')<div class="field-err">{{ $message }}</div>@enderror
      <p class="hint">源与目标允许相同（自关系，如主题关联自身）；反向关系请另行创建。</p>
    </div>

    <div class="form-row">
      <label>排序</label>
      <input type="number" name="sort_order" class="select w-80" min="0" step="1" value="{{ (int) $oldSort }}">
      @error('sort_order')<div class="field-err">{{ $message }}</div>@enderror
      <p class="hint">数值小的关系在 /geo.json 中靠前，默认 0。</p>
    </div>

    <div class="form-row">
      <label>关系备注（可选，JSON）</label>
      <textarea name="metadata_text" class="textarea mono" rows="3"
                placeholder='{"strength":"primary"}'>{{ $oldMeta }}</textarea>
      @error('metadata_text')<div class="field-err">{{ $message }}</div>@enderror
      <p class="hint">必须是合法 JSON 对象，或留空；备注不会出现在公开 /geo.json 中。</p>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-sm btn-primary">{{ $relation->exists ? '保存修改' : '创建关系' }}</button>
    <a class="btn btn-sm" href="{{ route('admin.relations.index') }}">取消</a>
  </div>
</form>
@endsection
