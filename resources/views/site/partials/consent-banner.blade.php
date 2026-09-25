{{--
  GEO Website OS Consent Banner（P-STEP 18H-3，Basic Consent Mode）
  仅当存在启用 provider 且「统计需访客同意」时输出。
  - 容器默认 hidden：仅「需要同意且尚未决定」时由脚本显示，已决定者不闪屏。
  - banner 自身不依赖任何第三方 analytics。
  - 拒绝：不加载第三方、不发第三方请求；接受：初始化并回放队列。
--}}
@php($geoConsent = app(\App\Support\Analytics\AnalyticsConfig::class))
@if($geoConsent->hasAnyProvider() && $geoConsent->consentRequired())
<style>
.geo-consent{position:fixed;left:0;right:0;bottom:0;z-index:1000;padding:var(--sp-4);
  background:var(--surface-elevated);border-top:1px solid var(--border);
  box-shadow:0 -10px 32px rgba(0,0,0,.14);}
.geo-consent[hidden]{display:none;}
.geo-consent__in{max-width:1200px;margin:0 auto;display:flex;gap:var(--sp-4);align-items:center;flex-wrap:wrap;}
.geo-consent__text{flex:1 1 320px;font-size:var(--fs-xs);line-height:1.6;color:var(--text-secondary);}
.geo-consent__title{font-weight:600;color:var(--text-primary);margin-bottom:var(--sp-1);}
.geo-consent__actions{display:flex;gap:var(--sp-3);flex:0 0 auto;}
@media (max-width:560px){ .geo-consent__actions{width:100%;} .geo-consent__actions .btn{flex:1;} }
</style>
<div id="geoConsentBanner" class="geo-consent" role="dialog" aria-modal="false"
     aria-labelledby="geoConsentTitle" aria-describedby="geoConsentText" hidden>
  <div class="geo-consent__in">
    <div class="geo-consent__text">
      <div id="geoConsentTitle" class="geo-consent__title">{{ __('ui.consent_title') }}</div>
      <div id="geoConsentText">{{ __('ui.consent_text') }}</div>
    </div>
    <div class="geo-consent__actions">
      <button type="button" id="geoConsentAccept" class="btn btn-primary">{{ __('ui.consent_accept') }}</button>
      <button type="button" id="geoConsentDeny" class="btn">{{ __('ui.consent_deny') }}</button>
    </div>
  </div>
</div>
<script nonce="{{ $cspNonce ?? '' }}">
(function(){
  function onReady(fn){
    if(document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', fn); }
    else { fn(); }
  }
  onReady(function(){
    var banner = document.getElementById('geoConsentBanner');
    var st = window.GeoAnalytics ? window.GeoAnalytics.state() : null;
    /* 仅「需要同意且尚未决定」时显示；已决定者保持 hidden。 */
    if(banner && st && st.needConsent && st.consent === null){ banner.hidden = false; }

    function close(state){
      if(window.GeoAnalytics){ window.GeoAnalytics.resolveConsent(state); }
      if(banner){ banner.hidden = true; }
    }
    var acc = document.getElementById('geoConsentAccept');
    var den = document.getElementById('geoConsentDeny');
    if(acc){ acc.addEventListener('click', function(){ close('accepted'); }); }
    if(den){ den.addEventListener('click', function(){ close('denied'); }); }
  });
})();
</script>
@endif
