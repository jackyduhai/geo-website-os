{{--
    首页中性欢迎屏（P-STEP 18I / TD-70）。
    --------------------------------------------------
    仅当 home 模板 main 槽「无任何区块」时由 site.composed 渲染：出厂 blank 首页的
    技术兜底，不含品牌 / 行业 / 产品业务事实——名称取 Site、说明取 Site description
    或翻译键。管理员在后台添加任意首页区块后，本欢迎屏自动被替换。
--}}
@php
    use App\Support\Catalog;
    use App\Support\PublicUrl;

    $welcomeName = trim((string) ($site->name ?? ''));
    $welcomeDesc = trim((string) ($site->description ?? ''));
    $hasCompany  = ! empty(Catalog::company());
@endphp
<section class="hero-section">
    <div class="container">
        <div class="hero-inner">
            <div class="hero-copy">
                <p class="eyebrow">{{ __('ui.blank_home_eyebrow') }}</p>
                <h1 class="display-1">{{ $welcomeName }}</h1>
                <p class="lead">{{ $welcomeDesc !== '' ? $welcomeDesc : __('ui.blank_home_lead') }}</p>
                <div class="hero-actions">
                    <a href="{{ PublicUrl::url('knowledge/') }}" class="btn btn-primary btn-lg">
                        {{ __('ui.browse_content') }}
                    </a>
                    @if($hasCompany)
                        <a href="{{ PublicUrl::url('contact/') }}" class="btn btn-outline btn-lg">
                            {{ __('ui.contact_us') }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>
