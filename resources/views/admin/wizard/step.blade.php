@extends('admin.layout')
@section('content')
@php
  // 进度条三态展示用：记录本会话已到达的最高步骤（只增不减）。
  // 回看较早步骤不会降低它，因此已越过的步骤仍显示为“已完成”，
  // 避免用户回看旧步骤后误以为初始化进度发生了回退。
  // 该值仅用于视图展示，不参与表单提交、落库或路由；向导提交仍以 URL 中的 step 为准（见 WizardController）。
  $maxReached = (int) session('wizard_max_step', 1);
  if ($step > $maxReached) {
      $maxReached = $step;
      session(['wizard_max_step' => $maxReached]);
  }
  $isReviewing = $step < $maxReached;
@endphp
<div class="wrap" style="max-width:820px;margin:0 auto;padding:24px">
  <h1>初始化引导</h1>
  <ol class="wizard-progress" style="display:flex;gap:12px;list-style:none;padding:0;margin:0 0 20px;flex-wrap:wrap;align-items:center;font-size:13px">
    @foreach($steps as $i=>$label)
      @php
        $isCurrent = $i === $step;
        $isDone    = ! $isCurrent && $i <= $maxReached;
        $isTodo    = $i > $maxReached;
      @endphp
      @if($isDone)
        <li class="wizard-step wizard-step-done">
          <a href="{{ route('admin.wizard', ['step' => $i]) }}"
             title="回看第 {{ $i }} 步：{{ $label }}"
             aria-label="回看第 {{ $i }} 步：{{ $label }}"
             style="color:var(--primary,#2563eb);text-decoration:none">✓ {{ $i }}. {{ $label }}</a>
        </li>
      @elseif($isCurrent)
        <li class="wizard-step wizard-step-current" aria-current="step"
            style="color:#0f172a;font-weight:700">{{ $i }}. {{ $label }}</li>
      @else
        <li class="wizard-step wizard-step-todo" aria-disabled="true"
            style="color:#94a3b8;cursor:not-allowed">{{ $i }}. {{ $label }}</li>
      @endif
    @endforeach
  </ol>
  @if($isReviewing)
    <p class="wizard-review-note" style="margin:0 0 16px;padding:10px 12px;border:1px solid var(--line,#e2e8f0);border-radius:8px;background:#f8fafc;color:#475569;font-size:13px">
      你正在回看第 {{ $step }} 步「{{ $steps[$step] }}」；此前已填写的信息仍保留，初始化进度不会回退。
      <a href="{{ route('admin.wizard', ['step' => $maxReached]) }}">返回当前进度：第 {{ $maxReached }} 步「{{ $steps[$maxReached] }}」</a>
    </p>
  @endif
  <form method="post" action="{{ route('admin.wizard.save',$step) }}">
    @csrf
    @if($step==1)
      <label>公司名 <input name="company_name" required></label>
      <label>邮箱 <input name="email"></label>
      <label>电话 <input name="phone"></label>
      <label>地址 <input name="address"></label>
    @elseif($step==2)
      <label>品牌色 <input name="brand_color" placeholder="#1a56db"></label>
      <label>品牌口号（Slogan） <input name="slogan"></label>
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
      <label>行业模板偏好
        <select name="template">
          <option value="default">通用</option>
          <option value="manufacturing">制造业</option>
          <option value="saas">软件服务（SaaS）</option>
          <option value="commerce">品牌电商</option>
        </select>
      </label>
      <p class="wizard-template-hint" style="margin:8px 0 0;max-width:560px;color:#6b7280;font-size:13px;line-height:1.6">
        本项仅记录模板偏好，不会在初始化向导中应用模板。完成初始化后，请前往模板页选择并应用实际模板。
      </p>
    @elseif($step==6)
      <p>即将把草稿产品发布为公开。完成后即可访问前台。</p>
    @endif
    <button class="btn" type="submit">{{ $step==6?'完成并发布':'下一步' }}</button>
    <a href="{{ route('admin.dashboard') }}" class="btn">跳过</a>
  </form>
</div>
@endsection
