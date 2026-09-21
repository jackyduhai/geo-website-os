@extends('admin.layout')
@section('title', $site->exists ? '编辑站点' : '新建站点')
@section('page-desc','站点是数据隔离的租户根。域名只需填裸域名（如 example.com），系统会自动去掉协议、端口与路径。')

@section('content')
<div class="card narrow">
  <h2>{{ $site->exists ? '编辑站点：'.$site->name : '新建站点' }}</h2>
  <form method="post"
        action="{{ $site->exists ? route('admin.sites.update', $site) : route('admin.sites.store') }}">
    @csrf @if($site->exists)@method('PUT')@endif

    <div class="form-grid">
      <div class="form-row"><label>站点名称 <span class="req">*</span></label>
        <input type="text" name="name" value="{{ old('name', $site->name) }}" maxlength="120" required>
        @error('name')<div class="field-err">{{ $message }}</div>@enderror
      </div>

      <div class="form-row"><label><span class="label-with-tip">标识 slug <span class="req">*</span>
        <x-admin-tip text="站点的稳定内部标识，只能是小写字母、数字与连字符；创建后不可修改，后台切换站点依赖它。"/></span></label>
        <input type="text" name="slug" value="{{ old('slug', $site->slug) }}" maxlength="80"
               placeholder="site-a" @if($site->exists) readonly aria-readonly="true" @endif required>
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

    <div class="form-row"><label><span class="label-with-tip">站点 Logo 路径
      <x-admin-tip text="媒体公开路径，如 /storage/logos/xxx.png。可先在媒体库上传图片后把路径填到这里；留空则使用系统默认标识。"/></span></label>
      <input type="text" name="logo" value="{{ old('logo', $site->logo) }}" maxlength="255"
             placeholder="/storage/...（可留空）">
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
