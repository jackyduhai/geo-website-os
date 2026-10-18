@foreach($itemFields as $sf)
  @php
    $sk = $sf['key'];
    $sv = is_array($val) ? ($val[$sk] ?? '') : '';
    $iname = "field[{$key}][{$ri}][{$sk}]";
  @endphp
  <td>
    @switch($sf['type'])
      @case('checkbox')
        <input type="hidden" name="{{ $iname }}" value="0">
        <input type="checkbox" name="{{ $iname }}" value="1" @checked(! empty($sv))>
        @break

      @case('media')
        @php
          // C026b：重复行（logo_cloud items[].media_id）媒体选择单元格。仍以原数字输入
          // 承载提交值（name=field[items][ri][media_id]，空→null、非空→int 的合同不变），
          // 选择/清除仅辅助回填，复用区块表单共享弹窗；40px 行内缩略图仅做已选回显。
          $mediaCellId = ($sv === null || $sv === '') ? null : (int) $sv;
          $mediaCell = ($mediaCellId !== null && isset($mediaPicker))
              ? $mediaPicker->firstWhere('id', $mediaCellId)
              : null;
        @endphp
        <div class="media-picker-cell" data-media-bound>
          <div class="media-picker-cell-row">
            <input type="number" name="{{ $iname }}" class="select media-picker-input"
                   value="{{ $mediaCellId ?? '' }}" inputmode="numeric">
            <button type="button" class="btn btn-sm" data-media-open>选择</button>
            <button type="button" class="btn btn-sm" data-media-clear>清除</button>
          </div>
          <div class="media-picker-cell-preview media-picker-preview" data-media-preview>
            @if($mediaCell)
              <span class="media-picker-preview-card">
                @if($mediaCell->isImage())
                  <img class="media-picker-thumb media-picker-thumb-sm" src="{{ $mediaCell->url() }}" alt="{{ $mediaCell->alt ?? '' }}">
                @else
                  <span class="media-picker-thumb media-picker-thumb-sm mono small">{{ $mediaCell->mime }}</span>
                @endif
                <span class="media-picker-meta">
                  <span class="media-picker-name">{{ $mediaCell->original_name }}</span>
                  <span class="small muted">ID {{ $mediaCell->id }}</span>
                </span>
              </span>
            @endif
          </div>
        </div>
        @break

      @case('number')
        <input type="number" name="{{ $iname }}" class="select w-90" value="{{ $sv }}">
        @break

      @case('select')
        <select name="{{ $iname }}" class="select">
          @foreach($sf['options'] as $optionValue => $optionLabel)
            {{-- 索引数组时元素值即提交值；关联数组时 key 为受控英文提交值、值为中文显示（同 fields/select）。 --}}
            @php
              if (is_int($optionValue)) {
                  $optionValue = $optionLabel;
              }
            @endphp
            <option value="{{ $optionValue }}" @selected((string) $sv === (string) $optionValue)>{{ $optionLabel }}</option>
          @endforeach
        </select>
        @break

      @case('icon')
        @php
          // C047：重复行（feature_grid items[].icon）可视化图标选择单元格。
          // 仍以原生 <select> 承载提交值（name=field[items][ri][icon]，英文 key 原样 trim 落库，
          // 17 个 option 的英文 value/中文 label 与 B3-3 契约保持一致），仅视觉隐藏；
          // 点击「选择图标」打开 block_form 的共享图标网格弹窗，点选后回填该 select 并更新行内预览。
          // 无已保存值时，行内预览取第一个选项（与浏览器对无 selected 的 select 默认提交首个 option 一致）。
          $iconOpts = $sf['options'] ?? [];
          $iconVal = (string) $sv;
          if (! array_key_exists($iconVal, $iconOpts)) {
              $firstIconKey = array_key_first($iconOpts);
              $iconVal = $firstIconKey === null ? '' : (string) $firstIconKey;
          }
          $iconValLabel = $iconOpts[$iconVal] ?? $iconVal;
        @endphp
        <div class="icon-picker-cell" data-icon-bound>
          <select name="{{ $iname }}" class="icon-picker-select" data-icon-select aria-label="图标">
            @foreach($sf['options'] as $optionValue => $optionLabel)
              @php
                if (is_int($optionValue)) {
                    $optionValue = $optionLabel;
                }
              @endphp
              <option value="{{ $optionValue }}" @selected((string) $sv === (string) $optionValue)>{{ $optionLabel }}</option>
            @endforeach
          </select>
          <span class="icon-picker-current" data-icon-current>
            <span class="icon-picker-glyph">@include('site._icon', ['name' => $iconVal, 'size' => 22])</span>
            <span class="icon-picker-current-label" data-icon-label>{{ $iconValLabel }}</span>
          </span>
          <button type="button" class="btn btn-sm" data-icon-open>选择图标</button>
        </div>
        @break

      @case('textarea')
        <textarea name="{{ $iname }}" class="textarea" rows="2">{{ $sv }}</textarea>
        @break

      @default
        <input type="text" name="{{ $iname }}" class="select" value="{{ $sv }}">
    @endswitch
  </td>
@endforeach
