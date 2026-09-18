@extends('admin.layout')
@section('title','站点设置')
@section('page-desc','公司信息、联系方式、全站文案、外观主题、SEO / GEO 与 GEOFlow 对接的全局设置，前台统一从此取值。')

@section('content')
<div class="filter-bar">
  @foreach(\App\Http\Controllers\Admin\SettingController::GROUPS as $gk=>$gv)
    <a class="tab {{ $group===$gk ? 'on' : '' }}" href="{{ route('admin.settings.index',$gk) }}">{{ $gv }}</a>
  @endforeach
</div>

<div class="card narrow-md">
  <h2>{{ \App\Http\Controllers\Admin\SettingController::GROUPS[$group] }}</h2>
  <form method="post" action="{{ route('admin.settings.update',$group) }}" enctype="multipart/form-data">
    @csrf @method('PUT')

    @foreach($settings as $s)
      <div class="form-row">
        <label><span class="label-with-tip">{{ $s->label }}
          @if($s->hint)<x-admin-tip type="info" :text="$s->hint"/>@endif
          <span class="small muted mono">[{{ $s->key }}]</span>
        </span></label>

        @if($s->type==='bool')
          <label class="checkline">
            <input type="checkbox" name="{{ $s->key }}" value="1" @checked(($s->value ?? '')==='1' || $s->value===1)>
            开启
          </label>
        @elseif($s->type==='textarea')
          <textarea name="{{ $s->key }}" rows="4">{{ old($s->key, $s->value) }}</textarea>
        @elseif($s->type==='number')
          <input type="number" name="{{ $s->key }}" value="{{ old($s->key, $s->value) }}">
        @elseif($s->type==='color')
          <input type="color" name="{{ $s->key }}" value="{{ old($s->key, $s->value) }}" class="color-input">
        @elseif($s->type==='image')
          @php($imgSize = match($s->key){ 'seo_og_image' => '社交分享图建议 1200×630（1.91:1）', 'geo_org_logo' => 'Logo 建议 512×512 以上方形 PNG', 'contact_wechat_qr' => '微信二维码建议正方形 600×600 以上 PNG', default => '建议使用清晰的 jpg/png/webp' })
          <input type="text" name="{{ $s->key }}" value="{{ old($s->key, $s->value) }}" list="media-paths" placeholder="路径或 URL，可从列表选择或直接上传">
          <datalist id="media-paths">
            @foreach($images as $img)<option value="{{ $img->url() }}">{{ $img->alt ?: $img->original_name }}</option>@endforeach
          </datalist>
          <input type="file" name="file_{{ $s->key }}" class="file-input mt-2" accept="image/*">
          <div class="help mt-1">{{ $imgSize }}，≤8MB</div>
          @if($s->value)<div class="small mt-2"><img src="{{ asset($s->value) }}" alt="" class="img-preview"></div>@endif
        @else
          <input type="text" name="{{ $s->key }}" value="{{ old($s->key, $s->value) }}">
        @endif

        @if($s->key==='sync_geoflow_token')
          <div class="small mono token-box mt-2">{{ $s->value ?: '尚未生成' }}</div>
        @endif
      </div>
    @endforeach

    <div class="form-actions">
      <button class="btn btn-primary">保存设置</button>
      @if($group==='sync')
        <form method="post" action="{{ route('admin.settings.token') }}" class="form-inline"
              onsubmit="return confirm('重新生成后旧 Token 立即失效，确认？')">@csrf
          <button class="btn">重新生成 Token</button>
        </form>
      @endif
    </div>
  </form>
</div>
@endsection
