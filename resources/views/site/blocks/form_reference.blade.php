{{-- 通用组合 Block · 咨询表单（FormReference，P-STEP 18H-2）。
     按 block 配置的 form_id 引用具体表单；留空回退站点默认联系表单。
     表单字段 / 校验 / 提交由产品化表单体系（dynamic_form）渲染，不再写死字段。
     每个 block 使用独立 form id，避免同页多表单 id 冲突。 --}}
@php
  $c = $block->cfg();
  $frTitle = trim((string) ($c['title'] ?? ''));
  $frSub = trim((string) ($c['subtitle'] ?? ''));
  $frFormId = 'block-lead-form-' . $block->id;

  $resolver = app(\App\Support\Forms\FormResolver::class);
  $frForm = $resolver->findById($c['form_id'] ?? null) ?? $resolver->defaultContact();
@endphp
@if($frForm)
  <section class="sec sec-tint" id="block-form-{{ $block->id }}">
    <div class="wrap-narrow">
      @if($frTitle !== '' || $frSub !== '')
        <div class="sec-head center">
          @if($frTitle)<h2 class="sec-h">{{ $frTitle }}</h2>@endif
          @if($frSub)<p class="sec-sub">{{ $frSub }}</p>@endif
        </div>
      @endif
      @include('site.dynamic_form', [
          'formModel' => $frForm,
          'leadFormId' => $frFormId,
          'leadClass' => 'standalone',
      ])
    </div>
  </section>
@endif
