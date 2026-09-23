<input type="number" name="field[{{ $key }}]" class="select w-120"
       value="{{ $cfg[$key] ?? '' }}">
<a class="btn btn-sm" target="_blank" href="{{ route('admin.media.index') }}">
  从媒体库选择（在新窗口查看并填入 ID）
</a>
