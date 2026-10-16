@php
  // C026 媒体选择器：仍以原有数字输入（name="field[<key>]"）承载提交值，
  // 保存合同（空→null、非空→int，见 PageController::extractConfig）完全不变；
  // 弹窗仅辅助把所选媒体的数字 ID 回填到该输入。
  $mediaRaw = $cfg[$key] ?? null;
  $mediaSelectedId = ($mediaRaw === null || $mediaRaw === '') ? null : (int) $mediaRaw;
  $mediaSelected = ($mediaSelectedId !== null && isset($mediaPicker))
      ? $mediaPicker->firstWhere('id', $mediaSelectedId)
      : null;
@endphp
<div class="media-picker-field" data-media-field="{{ $key }}">
  <input type="number" name="field[{{ $key }}]" id="media-input-{{ $key }}"
         class="select w-120 media-picker-input" value="{{ $mediaSelectedId ?? '' }}"
         inputmode="numeric">
  <button type="button" class="btn btn-sm" data-media-open="{{ $key }}">选择媒体</button>
  <button type="button" class="btn btn-sm" data-media-clear="{{ $key }}">清除</button>
  <a class="btn btn-sm" target="_blank" rel="noopener" href="{{ route('admin.media.index') }}">
    在媒体库管理 / 上传更多（新窗口）
  </a>
  <div id="media-preview-{{ $key }}" class="media-picker-preview" data-media-preview="{{ $key }}">
    @if($mediaSelected)
      <span class="media-picker-preview-card">
        @if($mediaSelected->isImage())
          <img class="media-picker-thumb" src="{{ $mediaSelected->url() }}" alt="{{ $mediaSelected->alt ?? '' }}">
        @else
          <span class="media-picker-thumb mono small">{{ $mediaSelected->mime }}</span>
        @endif
        <span class="media-picker-meta">
          <span class="media-picker-name">{{ $mediaSelected->original_name }}</span>
          <span class="small muted">ID {{ $mediaSelected->id }}</span>
        </span>
      </span>
    @endif
  </div>
</div>
