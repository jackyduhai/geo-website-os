@extends('admin.layout')
@section('title', $site->exists ? '编辑站点' : '新建站点')
@section('page-desc','站点是数据隔离的租户根。域名只需填裸域名（如 example.com），系统会自动去掉协议、端口与路径。')

@section('content')
<div class="card narrow">
  <h2>{{ $site->exists ? '编辑站点：'.$site->name : '新建站点' }}</h2>
  <form method="post" enctype="multipart/form-data"
        action="{{ $site->exists ? route('admin.sites.update', $site) : route('admin.sites.store') }}">
    @csrf @if($site->exists)@method('PUT')@endif

    <div class="form-grid">
      <div class="form-row"><label>站点名称 <span class="req">*</span></label>
        <input type="text" name="name" value="{{ old('name', $site->name) }}" maxlength="120" required>
        @error('name')<div class="field-err">{{ $message }}</div>@enderror
      </div>

      <div class="form-row"><label>标识 slug <span class="req">*</span></label>
        @if($site->exists)
          {{--已创建：slug 是内部稳定标识，改了会断掉既有链接与数据关联，因此只读展示 --}}
          <input type="text" value="{{ $site->slug }}" readonly aria-readonly="true"
                 class="input-readonly" tabindex="-1">
          <div class="field-hint">系统自动生成，不可修改。</div>
        @else
          {{-- 创建中：slug 尚未产生，保存时留空由系统按站点名自动生成 --}}
          <input type="hidden" name="slug" value="">
          <div class="field-hint">保存时由系统根据站点名称自动生成，无需填写。</div>
        @endif
        @error('slug')<div class="field-err">{{ $message }}</div>@enderror
      </div>
    </div>

    <div class="form-grid">
      <div class="form-row"><label><span class="label-with-tip">绑定域名
        <x-admin-tip text="填裸域名即可，如 example.com 或 www.example.com，无需 http(s)://、端口或路径；一个域名只能绑定一个站点。留空表示该站不通过独立域名访问。"/></span></label>
        <input type="text" name="domain" value="{{ old('domain', $site->domain) }}" maxlength="255"
               placeholder="example.com">
        @error('domain')<div class="field-err">{{ $message }}</div>@enderror
      </div>

      <div class="form-row"><label>状态 <span class="req">*</span></label>
        <select name="status">
          @foreach(['active'=>'运营中','maintenance'=>'维护中','inactive'=>'已停用'] as $vk=>$vn)
            <option value="{{ $vk }}" @selected(old('status', $site->status ?? 'active')===$vk)>{{ $vn }}</option>
          @endforeach
        </select>
        @error('status')<div class="field-err">{{ $message }}</div>@enderror
      </div>
    </div>

    <div class="form-row"><label>站点简介</label>
      <textarea name="description" rows="2" maxlength="5000">{{ old('description', $site->description) }}</textarea>
      @error('description')<div class="field-err">{{ $message }}</div>@enderror
    </div>

    <div class="form-row">
      <label><span class="label-with-tip">站点 Logo
        <x-admin-tip text="直接上传图片，无需手动填写服务器路径。建议 512×512 以上的正方形图片（PNG / JPG / WebP），系统会自动压缩并生成适配尺寸。留空则使用系统默认标识。"/></span></label>

      <div class="logo-upload">
        <div class="logo-preview">
          {{--空值回落必须与前台一致：都用方形 logo-icon.png。
     之前这里回落到横版 logo.png（1024×256），塞进 88×88 方框被压成横条，
     让运营者以为上传错了。 --}}
          @php $logoUrl = $site->logo ?: asset('img/logo-icon.png'); @endphp
          <img id="siteLogoPreview" src="{{ $logoUrl }}" alt="{{ $site->name }} Logo 预览">
        </div>
        <div class="logo-controls">
          <input type="file" id="siteLogoFile" accept="image/png,image/jpeg,image/webp,image/gif"
                 class="logo-file-input">
          <div class="logo-actions">
            <button type="button" class="btn btn-ghost btn-sm" id="siteLogoPick">选择图片</button>
            <button type="button" class="btn btn-ghost btn-sm hidden" id="siteLogoReset">还原</button>
          </div>
          <div class="field-hint">
            支持 PNG / JPG / WebP / GIF，不超过 2 MB。<br>
            建议<strong>正方形</strong>（如 512×512）；非正方形会按比例缩放，不裁切。
          </div>
          <input type="hidden" name="logo" id="siteLogoValue" value="{{ old('logo', $site->logo) }}">
          <input type="hidden" name="logo_remove" id="siteLogoRemove" value="0">
        </div>
      </div>
      @error('logo')<div class="field-err">{{ $message }}</div>@enderror
    </div>

    <div class="btn-row mt-2">
      @if($site->is_default)
        <span class="badge published">当前默认站点（始终保留，不可取消）</span>
      @else
        <label class="checkline">
          <input type="checkbox" name="is_default" value="1" @checked(old('is_default', false))>
          设为默认站点（同一时间仅一个，原默认站将被取消）
        </label>
      @endif
    </div>

    <div class="form-actions">
      <button class="btn btn-primary">保存</button>
      <a class="btn" href="{{ route('admin.sites.index') }}">返回</a>
    </div>
  </form>
</div>
@endsection

@push('scripts')
<script>
/**
 * 站点 Logo 上传交互。
 *
 * 运营者只做一件事：选文件 → 看预览 → 保存。
 * 隐藏域 `logo` 在选图后写入「移除标记 + 文件」组合：
 *   · 选了新文件 → 服务端存文件并覆盖 logo 路径
 *   · 点了移除   → logo_remove=1，服务端把 logo 置空（回默认标识）
 */
(function () {
  var file  = document.getElementById('siteLogoFile');
  var pick  = document.getElementById('siteLogoPick');
  var reset = document.getElementById('siteLogoReset');
  var prev  = document.getElementById('siteLogoPreview');
  var value = document.getElementById('siteLogoValue');
  var rm    = document.getElementById('siteLogoRemove');

  if (!file || !pick || !prev) return;

  var DEFAULT_LOGO = prev.getAttribute('src');   // 未设置 Logo 时的系统默认图
  var original     = value ? value.value : '';   // 打开页面时的既有路径

  // 「移除」按钮：首次点击进入待移除态，二次点击才真正清空
  var pendingRemove = false;

  function showReset() {
    if (reset) reset.classList.toggle('hidden', !original && !value.value);
  }

  pick.addEventListener('click', function () { file.click(); });

  file.addEventListener('change', function () {
    var f = file.files && file.files[0];
    if (!f) return;

    // 前端预检，与服务端校验规则一致，减少一次无谓往返
    if (f.size > 2 * 1024 * 1024) {
      alert('图片超过 2 MB，请压缩后再上传（当前 ' + (f.size / 1024 / 1024).toFixed(1) + ' MB）');
      file.value = '';
      return;
    }

    pendingRemove = false;
    if (rm) rm.value = '0';
    prev.src = URL.createObjectURL(f);
    showReset();
  });

  if (reset) {
    reset.addEventListener('click', function () {
      if (pendingRemove) {
        // 二次点击：确认清空
        if (value) value.value = '';
        if (rm) rm.value = '1';
        prev.src = DEFAULT_LOGO;
        file.value = '';
        pendingRemove = false;
        reset.classList.add('hidden');
        reset.textContent = '选择图片';
        return;
      }
      // 首次点击：进入待确认态（仅当原本有自定义 Logo 时才需要）
      if (!original) return;
      pendingRemove = true;
      prev.src = DEFAULT_LOGO;
      reset.textContent = '确认移除';
    });
  }

  showReset();
})();
</script>
@endpush
