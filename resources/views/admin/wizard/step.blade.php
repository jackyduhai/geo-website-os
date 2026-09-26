@extends('admin.layout')
@section('content')
<div class="wrap" style="max-width:820px;margin:0 auto;padding:24px">
  <h1>Setup 引导</h1>
  <ol style="display:flex;gap:12px;list-style:none;padding:0">
    @foreach($steps as $i=>$label)
      <li style="opacity: {{ $i==$step?1:0.5 }}">{{ $i }}. {{ $label }}</li>
    @endforeach
  </ol>
  <form method="post" action="{{ route('admin.wizard.save',$step) }}">
    @csrf
    @if($step==1)
      <label>公司名 <input name="company_name" required></label>
      <label>邮箱 <input name="email"></label>
      <label>电话 <input name="phone"></label>
      <label>地址 <input name="address"></label>
    @elseif($step==2)
      <label>品牌色 <input name="brand_color" placeholder="#1a56db"></label>
      <label>Slogan <input name="slogan"></label>
    @elseif($step==3)
      @for($i=0;$i<3;$i++)
        <fieldset>
          <legend>产品 {{ $i+1 }}</legend>
          <label>名称 <input name="products[{{ $i }}][name]"></label>
          <label>简介 <input name="products[{{ $i }}][summary]"></label>
        </fieldset>
      @endfor
    @elseif($step==4)
      <p>自动把已创建的产品挂到 Organization（produces 关系）。</p>
    @elseif($step==5)
      <label>模板
        <select name="template">
          <option value="default">default</option>
          <option value="manufacturing">manufacturing</option>
          <option value="saas">saas</option>
          <option value="commerce">commerce</option>
        </select>
      </label>
    @elseif($step==6)
      <p>即将把草稿产品发布为公开。完成后即可访问前台。</p>
    @endif
    <button class="btn" type="submit">{{ $step==6?'完成并发布':'下一步' }}</button>
    <a href="{{ route('admin.dashboard') }}" class="btn">跳过</a>
  </form>
</div>
@endsection
