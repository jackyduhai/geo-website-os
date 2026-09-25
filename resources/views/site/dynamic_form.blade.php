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
        action="{{ localized_route('forms.submit', $formModel->slug) }}"
        data-form-id="{{ $formModel->id }}" data-form-slug="{{ $formModel->slug }}" novalidate>
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
  var GEN_ERR = @json(__('ui.form_error'));

  function fieldBox(input){ return input.closest('.lf-field'); }
  function clearFeedback(form){
    form.querySelectorAll('.lf-field.has-error').forEach(function(b){ b.classList.remove('has-error'); });
    form.querySelectorAll('.lf-err.is-inline').forEach(function(e){ e.remove(); });
    var box = form.querySelector('.lead-form-err'); if(box) box.remove();
    form.removeAttribute('aria-busy');
  }
  function clientValidate(form){
    var first = null;
    form.querySelectorAll('[required]').forEach(function(el){
      var bad = false, name = el.name;
      if(el.type === 'checkbox' || el.type === 'radio'){
        bad = !form.querySelector('[name="' + CSS.escape(name) + '"]:checked');
      } else {
        bad = (el.value || '').trim() === '';
      }
      fieldBox(el).classList.toggle('has-error', bad);
      if(bad && !first) first = el;
    });
    return first;
  }
  function setBusy(form, busy){
    form.setAttribute('aria-busy', busy ? 'true' : 'false');
    var btn = form.querySelector('button[type=submit]');
    if(!btn) return;
    if(busy){ btn.classList.add('is-loading'); btn.disabled = true; }
    else { btn.classList.remove('is-loading'); btn.disabled = false; }
  }
  function showFieldErrors(form, errors){
    Object.keys(errors).forEach(function(name){
      var base = name.split('.')[0];
      var input = form.querySelector('[name="' + base + '"], [name="' + base + '[]"]');
      if(!input) return;
      var box = fieldBox(input);
      box.classList.add('has-error');
      if(box.querySelector('.lf-err.is-inline')) return;
      var p = document.createElement('p');
      p.className = 'lf-err is-inline';
      p.textContent = errors[name][0] || name;
      var help = box.querySelector('.lf-help');
      if(help) help.insertAdjacentElement('afterend', p); else box.appendChild(p);
    });
  }
  function showSuccess(form, message){
    var ok = document.createElement('div');
    ok.className = 'lead-ok';
    ok.setAttribute('role', 'status');
    ok.tabIndex = -1;
    ok.textContent = message;
    form.parentNode.replaceChild(ok, form);
    ok.focus();
  }
  function showFormError(form){
    if(form.querySelector('.lead-form-err')) return;
    var box = document.createElement('div');
    box.className = 'lead-form-err';
    box.setAttribute('role', 'alert');
    box.textContent = GEN_ERR;
    form.parentNode.insertBefore(box, form);
  }
  function wire(form){
    if(!form || form.__wired) return;
    form.__wired = true;
    form.addEventListener('submit', function(e){
      clearFeedback(form);
      var first = clientValidate(form);
      if(first){ e.preventDefault(); first.focus(); return; }
      if(!window.fetch || !window.FormData) return; /* 无 fetch：原生整页提交兜底 */
      e.preventDefault();
      setBusy(form, true);
      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
      }).then(function(res){
        var ct = res.headers.get('content-type') || '';
        if(res.ok && ct.indexOf('application/json') !== -1){
          return res.json().then(function(j){ showSuccess(form, j.message || ''); });
        }
        if(res.status === 422){
          return res.json().then(function(j){
            showFieldErrors(form, j.errors || {});
            var b = form.querySelector('.lf-field.has-error');
            if(b){ var i = b.querySelector('input,select,textarea'); if(i) i.focus(); }
            setBusy(form, false);
          });
        }
        throw new Error('HTTP ' + res.status);
      }).catch(function(){
        if(document.body.contains(form)){ setBusy(form, false); showFormError(form); }
      });
    });
  }
  document.querySelectorAll('form.dynamic-form').forEach(wire);
  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('form.dynamic-form').forEach(wire);
  });
})();
</script>
