{{-- 全局统一咨询表单（Global Inquiry Form）：全站同一套字段 / API / 数据结构。
     仅 4 个可见字段：称呼、联系电话、客户类型、需求简述（选填）。
     来源归因由 CaptureAttribution 中间件写入隐藏字段，用户无需填写。 --}}
@php
  $formCopy = \App\Support\Copy::form();
  $ff = $formCopy['fields'];
  $customerOptions = $ff['customerType']['options'] ?? [];
  $formId = $leadFormId ?? 'lead-form';
  $formClass = $leadClass ?? '';
@endphp

@if(session('lead_success'))
  <div class="lead-ok" role="status">{{ session('lead_success') ?: $formCopy['success'] }}</div>
@else
  <form class="lead-form {{ $formClass }}" id="{{ $formId }}" method="post" action="{{ route('inquiry.store') }}" novalidate>
    @csrf
    {{-- 蜜罐字段，真人不可见 --}}
    <div class="hp" aria-hidden="true">
      <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>

    {{-- 来源归因：由中间件在首次访问时写入 --}}
    @php $attr = $leadAttr ?? []; @endphp
    <input type="hidden" name="landing_url" value="{{ $attr['landing_url'] ?? '' }}">
    <input type="hidden" name="referer" value="{{ $attr['referer'] ?? '' }}">
    <input type="hidden" name="utm_source" value="{{ $attr['utm_source'] ?? '' }}">
    <input type="hidden" name="utm_medium" value="{{ $attr['utm_medium'] ?? '' }}">
    <input type="hidden" name="utm_campaign" value="{{ $attr['utm_campaign'] ?? '' }}">
    <input type="hidden" name="utm_term" value="{{ $attr['utm_term'] ?? '' }}">
    <input type="hidden" name="utm_content" value="{{ $attr['utm_content'] ?? '' }}">

    <div class="lf-grid">
      <div class="lf-field">
        <label for="{{ $formId }}-name">{{ $ff['name']['label'] }}<span class="req">*</span></label>
        <input id="{{ $formId }}-name" type="text" name="name" value="{{ old('name') }}"
               placeholder="{{ $ff['name']['placeholder'] }}" maxlength="50" autocomplete="name"
               data-required aria-required="true" data-err="{{ $ff['name']['error'] }}">
        <p class="lf-err" hidden></p>
      </div>

      <div class="lf-field">
        <label for="{{ $formId }}-phone">{{ $ff['phone']['label'] }}<span class="req">*</span></label>
        <input id="{{ $formId }}-phone" type="tel" inputmode="tel" name="phone" value="{{ old('phone') }}"
               placeholder="{{ $ff['phone']['placeholder'] }}" maxlength="30" autocomplete="tel"
               data-required aria-required="true" data-phone data-err="{{ $ff['phone']['error'] }}">
        <p class="lf-err" hidden></p>
      </div>

      <div class="lf-field lf-full">
        <label for="{{ $formId }}-type">{{ $ff['customerType']['label'] }}<span class="req">*</span></label>
        <select id="{{ $formId }}-type" name="demand_type" data-required aria-required="true" data-err="{{ $ff['customerType']['error'] }}">
          <option value="" disabled @selected(! old('demand_type'))>{{ $ff['customerType']['placeholder'] }}</option>
          @foreach($customerOptions as $opt)
            <option value="{{ $opt }}" @selected(old('demand_type') === $opt)>{{ $opt }}</option>
          @endforeach
        </select>
        <p class="lf-err" hidden></p>
      </div>

      <div class="lf-field lf-full">
        <label for="{{ $formId }}-note">{{ $ff['note']['label'] }}</label>
        <textarea id="{{ $formId }}-note" name="message" rows="3"
                  placeholder="{{ $ff['note']['placeholder'] }}" maxlength="1000">{{ old('message') }}</textarea>
      </div>

      <div class="lf-full lf-submit">
        <button class="btn btn-lg btn-primary" type="submit" data-default="{{ $formCopy['submit'] }}">
          {{ $formCopy['submit'] }}
        </button>
      </div>
      <p class="lf-privacy lf-full">{{ $formCopy['privacy'] }}</p>
      @error('name')<p class="lf-err lf-full">{{ $message }}</p>@enderror
      @error('phone')<p class="lf-err lf-full">{{ $message }}</p>@enderror
      @error('demand_type')<p class="lf-err lf-full">{{ $message }}</p>@enderror
    </div>
  </form>
@endif

{{-- 极简原生校验：onBlur 触发（非 onInput），提交时锁定防重复点击；可完全降级（novalidate 后端兜底） --}}
<script nonce="{{ $cspNonce ?? '' }}">
(function(){
  function wire(form){
    if(!form || form.__wired) return; form.__wired = true;
    function show(input, msg){
      var p = input.parentElement.querySelector('.lf-err');
      input.classList.toggle('invalid', !!msg);
      if(p){ p.textContent = msg || ''; p.hidden = !msg; }
      return !msg;
    }
    function validate(input){
      var v = (input.value || '').trim(), msg = '';
      if(input.hasAttribute('data-required') && v === ''){ msg = input.dataset.err || '此项必填'; }
      else if(input.hasAttribute('data-phone') && v !== '' && !/^[0-9+\-\s()wx微信,，]{6,30}$/.test(v)){ msg = @json($ff['phone']['invalid']); }
      return show(input, msg);
    }
    form.querySelectorAll('[data-required],[data-phone]').forEach(function(input){
      input.addEventListener('blur', function(){ validate(input); });
      input.addEventListener('input', function(){ if(input.classList.contains('invalid')) validate(input); });
    });
    form.addEventListener('submit', function(e){
      var ok = true;
      form.querySelectorAll('[data-required],[data-phone]').forEach(function(i){ if(!validate(i)) ok = false; });
      if(!ok){ e.preventDefault(); var first = form.querySelector('.invalid'); if(first) first.focus(); return; }
      var btn = form.querySelector('button[type=submit]');
      if(btn){ btn.disabled = true; btn.textContent = @json($formCopy['submitting']); }
    });
  }
  document.querySelectorAll('form.lead-form').forEach(wire);
  document.addEventListener('DOMContentLoaded', function(){ document.querySelectorAll('form.lead-form').forEach(wire); });
})();
</script>
