{{-- 通用组合 Block · 咨询表单（FormReference）：引用全局统一 Inquiry 表单。
     18G-1 仅做组合层接线；完整 Form Builder（字段 / 校验 / 通知 / 防 spam / 授权）归 18H。
     每个 block 使用独立 form id，避免同页多表单 id 冲突。 --}}
@php
  $c = $block->cfg();
  $frTitle = trim((string) ($c['title'] ?? ''));
  $frSub = trim((string) ($c['subtitle'] ?? ''));
  $frFormId = 'block-lead-form-' . $block->id;
@endphp
<section class="sec sec-tint" id="block-form-{{ $block->id }}">
  <div class="wrap-narrow">
    @if($frTitle !== '' || $frSub !== '')
      <div class="sec-head center">
        @if($frTitle)<h2 class="sec-h">{{ $frTitle }}</h2>@endif
        @if($frSub)<p class="sec-sub">{{ $frSub }}</p>@endif
      </div>
    @endif
    @include('site._lead_form', ['leadFormId' => $frFormId, 'leadClass' => 'standalone'])
  </div>
</section>
