{{-- 通用布尔开关：依赖外层循环变量 $c（栏目模型） --}}
<form class="form-inline" method="post" action="{{ route('admin.categories.toggle', $c) }}">
  @csrf
  <input type="hidden" name="field" value="{{ $field }}">
  <button type="submit" class="badge {{ $on ? 'ok' : 'draft' }}">{{ $on ? '是' : '否' }}</button>
</form>
