{{-- 产品化动态表单（P-STEP 18H-2）。
     接收 $formModel（Form）；字段按当前 locale 渲染，蜜罐 / 归因 / 提交锁定齐备。
     视觉沿用 lead-form 设计系统；提交走 forms.submit（locale 组自动加前缀）。 --}}
@php
  $formId = $leadFormId ?? ('dyn-form-' . $formModel->id);
  $formClass = $leadClass ?? '';
  $locale = $formLocale ?? \App\Support\Localization\LocaleContext::current();
  $fields = $formModel->fieldsForLocale($locale);
  if ($fields->isEmpty() && $locale !== \App\Support\Localization\LocaleRegistry::default()) {
      $fields = $formModel->fieldsForLocale(\App\Support\Localization\LocaleRegistry::default());
  }
  $honeypotName = (string) config('forms.honeypot_field', 'website');
@endphp

@if(session('lead_success'))
  <div class="lead-ok" role="status">{{ session('lead_success') }}</div>
@else
  <form class="lead-form dynamic-form {{ $formClass }}" id="{{ $formId }}" method="post"
        action="{{ localized_route('forms.submit', $formModel->slug) }}" novalidate>
    @csrf

    @if($formModel->honeypot_enabled)
      <div class="hp" aria-hidden="true">
        <label>Website<input type="text" name="{{ $honeypotName }}" tabindex="-1" autocomplete="off"></label>
      </div>
    @endif

    @php $attr = $leadAttr ?? []; @endphp
    <input type="hidden" name="landing_url" value="{{ $attr['landing_url'] ?? '' }}">
    <input type="hidden" name="referer" value="{{ $attr['referer'] ?? '' }}">
    <input type="hidden" name="utm_source" value="{{ $attr['utm_source'] ?? '' }}">
    <input type="hidden" name="utm_medium" value="{{ $attr['utm_medium'] ?? '' }}">
    <input type="hidden" name="utm_campaign" value="{{ $attr['utm_campaign'] ?? '' }}">
    <input type="hidden" name="utm_term" value="{{ $attr['utm_term'] ?? '' }}">
    <input type="hidden" name="utm_content" value="{{ $attr['utm_content'] ?? '' }}">

    <div class="lf-grid">
      @foreach($fields as $field)
        @include('site.partials.form_field', ['field' => $field, 'formId' => $formId])
      @endforeach

      <div class="lf-full lf-submit">
        <button class="btn btn-lg btn-primary" type="submit">{{ __('ui.form_submit') }}</button>
      </div>
    </div>
  </form>
@endif

<script nonce="{{ $cspNonce ?? '' }}">
(function(){
  function wire(form){
    if(!form || form.__wired) return; form.__wired = true;
    form.addEventListener('submit', function(e){
      var first = null;
      form.querySelectorAll('[required]').forEach(function(el){
        var bad = false, name = el.name;
        if(el.type === 'checkbox' || el.type === 'radio'){
          bad = !form.querySelector('[name="' + CSS.escape(name) + '"]:checked');
        } else {
          bad = (el.value || '').trim() === '';
        }
        el.classList.toggle('invalid', bad);
        if(bad && !first) first = el;
      });
      if(first){ e.preventDefault(); first.focus(); return; }
      var btn = form.querySelector('button[type=submit]');
      if(btn){ btn.disabled = true; btn.textContent = @json(__('ui.form_submitting')); }
    });
  }
  document.querySelectorAll('form.dynamic-form').forEach(wire);
  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('form.dynamic-form').forEach(wire);
  });
})();
</script>
