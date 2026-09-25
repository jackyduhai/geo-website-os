<?php

namespace App\Support\Theme;

/**
 * ThemePalette —— GEO Website OS 设计令牌派生引擎（P-STEP 18D）。
 *
 * 单一职责：把后台「外观与主题」里少量的**种子设置**（一个品牌主色、一个辅色、
 * 中性底色 / 文字色、圆角、容器宽度、密度、阴影质感）派生为整套**语义化 CSS 令牌**，
 * 供 layouts/site.blade.php 的 :root 一次性消费。
 *
 * 设计原则：
 *   - 管理员只需选一个品牌基色（Brand Seed），悬停 / 按下 / 浅底 / 反白文字、
 *     辅色簇、CTA 簇、首屏深色渐变全部自动派生，保证协调，不再要求逐字段手填；
 *   - 全部为纯函数（无 IO、无 DB、无随机），相同输入恒定输出，便于单元测试与缓存；
 *   - 红（error）固定不随品牌变化，品牌色不承担错误语义；
 *   - 任何用于文字 / 图标 / 实心按钮的品牌色、辅色、CTA 色都派生到 WCAG AA
 *     （对比度 ≥ 4.5:1）；原始明亮种子色仅用于浅底（*-soft）、光晕、首屏渐变等
 *     不承载正文对比的装饰层（--*-bright 专供深色反白区点缀）；
 *   - 派生只改变**视觉语言**（颜色 / 圆角 / 质感 / 密度），绝不产出任何行业 IA / 文案。
 *
 * 颜色空间：sRGB 线性插值（mix）+ WCAG 2.x 相对亮度决定对比度与反白文字（on-color）。
 */
class ThemePalette
{
    /** 出厂中性默认种子（与 DefaultSettingSeeder 的 theme 组保持一致）。 */
    public const DEFAULTS = [
        'brand'        => '#2563EB',
        'accent'       => '#64748B', // 蓝灰小面积点缀（P-STEP 18K：出厂不再默认独立绿色）
        'bg'           => '#F8FAFC',
        'surface'      => '#FFFFFF',
        'ink'          => '#1F2937',
        'muted'        => '#6B7280',
        'radius'       => 10,
        'container'    => 1200,
        'font'         => '',
        'density'      => 'comfortable',
        'shadow'       => 'flat',
        'primaryDark'  => '', // 留空 = 自动派生；高级用户可显式覆盖悬停色
    ];

    /** 正文 / 实心按钮的 WCAG AA 对比度门槛。 */
    public const AA_RATIO = 4.5;

    /** 深底压字 / 首屏渐变统一使用的中性深蓝黑（替代历史暖褐食品色）。 */
    private const DEEP = '#0B1220';

    /**
     * 从站点设置数组解析出完整 CSS 自定义属性映射。
     *
     * @param  array<string,mixed>  $settings  Setting::allCached() 形态（key => value）
     * @return array<string,string> 键为 CSS 变量名（含 -- 前缀），值为不含分号的 CSS 值
     */
    public static function resolve(array $settings, array $themeTokens = []): array
    {
        // 视觉种子三层：站点后台显式设置（$settings，Custom Brand / Preset）最高，
        // 激活主题自带种子（$themeTokens）为默认层，DEFAULTS 兜底。
        // 站点显式值中的空字符串视为“未设置”，回落主题种子而非直接跳 DEFAULTS。
        $get = static function (string $key, $default) use ($settings, $themeTokens) {
            if (isset($settings[$key]) && trim((string) $settings[$key]) !== '') {
                return trim((string) $settings[$key]);
            }
            if (array_key_exists($key, $themeTokens) && trim((string) $themeTokens[$key]) !== '') {
                return trim((string) $themeTokens[$key]);
            }
            return $default;
        };

        // 原始种子色（明亮、保留品牌识别），仅用于装饰层（浅底 / 光晕 / 渐变 / 深底点缀）。
        $brandRaw  = self::hexToRgb((string) $get('theme_primary', self::DEFAULTS['brand']));
        $accentRaw = self::hexToRgb((string) $get('theme_accent', self::DEFAULTS['accent']));

        // 文字 / 图标 / 实心按钮用色：派生到与白互为 AA（足够深），同一深度同时保证
        // 「该色作底 + 白字」与「该色作文字 / 图标 + 白底」对比度 ≥ 4.5。
        $brand  = self::deepenForContrast($brandRaw, self::AA_RATIO);
        $accent = self::deepenForContrast($accentRaw, self::AA_RATIO);

        $bg      = self::normalizeHex((string) $get('theme_bg', self::DEFAULTS['bg']), self::DEFAULTS['bg']);
        $surface = self::normalizeHex((string) $get('theme_surface', self::DEFAULTS['surface']), self::DEFAULTS['surface']);
        $ink     = self::normalizeHex((string) $get('theme_text', self::DEFAULTS['ink']), self::DEFAULTS['ink']);
        $muted   = self::normalizeHex((string) $get('theme_text_muted', self::DEFAULTS['muted']), self::DEFAULTS['muted']);

        // 主色悬停：高级字段显式填写合法 hex 则尊重（同样派生到 AA），否则在达标品牌色上再向黑混合 12%。
        $primaryDark = ltrim(trim((string) $get('theme_primary_dark', '')), '#');
        $brandDark   = preg_match('/^[0-9a-fA-F]{6}$/', $primaryDark)
            ? self::deepenForContrast(self::hexToRgb('#' . $primaryDark), self::AA_RATIO)
            : self::mix($brand, '#000000', 0.12);

        $radius    = self::clampInt((int) $get('theme_radius', self::DEFAULTS['radius']), 0, 48, 10);
        $container = self::clampInt((int) $get('theme_container', self::DEFAULTS['container']), 800, 2400, 1200);
        $font      = (string) $get('theme_font', '');
        $density = (string) $get('theme_density', self::DEFAULTS['density']);
        if (! in_array($density, ['comfortable', 'compact'], true)) {
            $density = 'comfortable';
        }
        $shadowMode = (string) $get('theme_shadow', self::DEFAULTS['shadow']);
        if (! in_array($shadowMode, ['flat', 'soft'], true)) {
            $shadowMode = 'flat';
        }

        // ---- 品牌主色簇（文字 / 实心用加深达标版，浅底与光晕用原始种子）----
        $brandActive = self::mix($brand, '#000000', 0.22);
        $brandSoft   = self::mix($brandRaw, '#FFFFFF', 0.90);
        $onBrand     = self::onColor($brand);

        // ---- 辅色簇 ----
        $accentDark = self::mix($accent, '#000000', 0.12);
        $accentSoft = self::mix($accentRaw, '#FFFFFF', 0.90);
        $onAccent   = self::onColor($accent);

        // ---- Action 簇：默认与品牌同色系（Brand-led + Action-aligned，P-STEP 18K）----
        // 主行动不再默认使用独立色相（历史为绿色 CTA），而是品牌色的同色系派生，
        // 保证「换品牌 = 主行动一起换」；Accent 退为小面积点缀（tag / 数据 / 装饰）。
        $action       = $brand;
        $actionDark   = $brandDark;
        $actionActive = $brandActive;
        $actionSoft   = $brandSoft;
        $onAction     = $onBrand;

        // ---- CTA 簇：核心实心按钮默认走品牌同色系，白字 AA ----
        // mix 黑 4% 给出同色系略深面，再保证实心按钮「白字 AA」：
        // 亮种子（如琥珀）加深到白底 AA 后仍处白/黑字临界，需继续向黑到白字达标，
        // 正常深色品牌 deepenForContrast 原样返回、不受影响。
        $cta     = self::deepenForContrast(self::mix($brand, '#000000', 0.04), self::AA_RATIO);
        $ctaDark = self::mix($cta, '#000000', 0.12);
        $ctaSoft = $brandSoft;
        $onCta   = self::onColor($cta);

        // ---- 语义成功色：固定积极绿（与 error 固定红对称），不随品牌 / accent 改变 ----
        $success = self::deepenForContrast(self::hexToRgb('#16A34A'), self::AA_RATIO);

        // ---- 干净中性阶（约 70% 界面） ----
        $surface2 = self::mix($surface, $ink, 0.045);
        $surface3 = self::mix($surface, $ink, 0.085);
        $ink2     = self::mix($surface, $ink, 0.82);
        $inkFaint = self::mix($surface, $ink, 0.55);
        $line     = self::mix($surface, $ink, 0.14);
        $lineSoft = self::mix($surface, $ink, 0.075);

        // ---- 深色反白区（页脚 / 反白 section） ----
        $footerBg  = self::mix($ink, '#000000', 0.78);
        $footerInk = self::mix($footerBg, '#FFFFFF', 0.62);
        $footerDim = self::mix($footerBg, '#FFFFFF', 0.42);

        // ---- 首屏深色品牌渐变（替代历史酒红食品渐变；用明亮原始种子在深底上发光）----
        $heroBase = self::mix($brandRaw, self::DEEP, 0.60);
        $heroMid  = self::mix($brandRaw, self::DEEP, 0.72);
        $heroDeep = self::mix($brandRaw, self::DEEP, 0.84);
        $heroBack = self::mix($brandRaw, self::DEEP, 0.90);
        $heroGlowColor = self::rgba($accentRaw, 0.10);
        $heroKicker    = self::mix($brandRaw, '#FFFFFF', 0.72);
        $heroGradient = sprintf(
            'radial-gradient(120%% 140%% at 18%% 12%%,%s,%s 60%%),'
            . 'linear-gradient(102deg,%s 0%%,%s 32%%,%s 56%%,%s 78%%,%s 100%%)',
            self::rgba($brandRaw, 0.42), self::rgba($brandRaw, 0.0),
            self::hex($heroBase), self::hex($heroMid), self::hex($heroDeep),
            self::hex($heroBack), self::hex(self::mix($brandRaw, self::DEEP, 0.94))
        );
        $heroGlow = "radial-gradient(56% 76% at 82% 46%,{$heroGlowColor}," . self::rgba($accentRaw, 0.0) . ' 64%)';
        $heroVeil = sprintf(
            'linear-gradient(90deg,%s 0%%,%s 30%%,%s 52%%)',
            self::rgba(self::DEEP, 0.50), self::rgba(self::DEEP, 0.18), self::rgba(self::DEEP, 0.0)
        );

        // ---- 图片压字遮罩（中性深蓝黑，替代暖褐 rgba(20,10,8)） ----
        $scrim = self::rgbString(self::DEEP);

        // ---- 密度（仅控制 section 纵向节奏，最小风险） ----
        $sectionY = $density === 'compact' ? '64px' : '96px';

        // ---- 阴影质感（去盒子化：默认静态无阴影，仅悬浮 / 浮层） ----
        $inkRgb = self::rgbString($ink);
        if ($shadowMode === 'soft') {
            $shadowSm      = "0 1px 2px rgba({$inkRgb},.06)";
            $shadowHover   = "0 10px 28px rgba({$inkRgb},.12)";
            $shadowOverlay = "0 18px 44px rgba({$inkRgb},.16)";
        } else {
            $shadowSm      = "0 1px 2px rgba({$inkRgb},.05)";
            $shadowHover   = "0 2px 8px rgba({$inkRgb},.08)";
            $shadowOverlay = "0 8px 24px rgba({$inkRgb},.10)";
        }

        // ---- 圆角分级（基础圆角可后台配置，档位围绕其收敛，禁 >18） ----
        $rSm = max(4, $radius - 4);
        $rLg = $radius + 2;
        $rXl = min(18, $radius + 6);

        // ---- P-STEP 18L-1：Typography profile（排版气质：字号 scale / 行高，随主题切换）----
        $typoProfile = strtolower((string) $get('theme_typography', 'standard'));
        if (! in_array($typoProfile, ['standard', 'compact', 'editorial'], true)) {
            $typoProfile = 'standard';
        }
        $fs = self::typographyScale($typoProfile);
        $lhHeading = ['standard' => '1.25', 'compact' => '1.2', 'editorial' => '1.12'][$typoProfile];
        $lhBody    = ['standard' => '1.75', 'compact' => '1.55', 'editorial' => '1.8'][$typoProfile];

        $fontStackDefault = '-apple-system,BlinkMacSystemFont,"PingFang SC","Hiragino Sans GB","Microsoft YaHei","Helvetica Neue",Arial,sans-serif';
        $fontStack = $font !== '' ? $font : $fontStackDefault;
        // 英文（拉丁）优先字体栈：系统字体优先，不下载 web font（Inter 安装则用，否则 SF Pro / Segoe UI / Roboto 回退）。
        $fontStackEn = 'Inter,"SF Pro Text","Segoe UI",Roboto,Helvetica,Arial,sans-serif';

        return [
            // 品牌（文字 / 实心用加深达标版，--*-bright 为深底点缀用原始种子）
            '--brand'         => self::hex($brand),
            '--brand-dark'    => self::hex($brandDark),
            '--brand-active'  => self::hex($brandActive),
            '--brand-soft'    => self::hex($brandSoft),
            '--brand-on'      => self::hex($onBrand),
            '--brand-ring'    => self::rgba($brandRaw, 0.16),
            '--brand-bright'  => self::hex($brandRaw),
            // 辅色
            '--accent'        => self::hex($accent),
            '--accent-dark'   => self::hex($accentDark),
            '--accent-soft'   => self::hex($accentSoft),
            '--accent-on'     => self::hex($onAccent),
            '--accent-ring'   => self::rgba($accentRaw, 0.20),
            '--accent-bright' => self::hex($accentRaw),
            // Action（品牌同色系主行动，P-STEP 18K）
            '--action'        => self::hex($action),
            '--action-dark'   => self::hex($actionDark),
            '--action-active' => self::hex($actionActive),
            '--action-soft'   => self::hex($actionSoft),
            '--action-on'     => self::hex($onAction),
            // CTA（默认映射到品牌同色系，白字 AA）
            '--cta'          => self::hex($cta),
            '--cta-dark'     => self::hex($ctaDark),
            '--cta-soft'     => self::hex($ctaSoft),
            '--cta-on'       => self::hex($onCta),
            // 中性
            '--hd-bg'        => 'rgba(255,255,255,.86)',  /* 吸顶导航 / 吸顶子导航毛玻璃（深色态由 darkOverrides 覆盖） */
            '--bg'           => self::hex($bg),
            '--surface'      => self::hex($surface),
            '--surface-2'    => self::hex($surface2),
            '--surface-3'    => self::hex($surface3),
            '--ink'          => self::hex($ink),
            '--ink-2'        => self::hex($ink2),
            '--ink-muted'    => self::hex($muted),
            '--ink-faint'    => self::hex($inkFaint),
            '--line'         => self::hex($line),
            '--line-soft'    => self::hex($lineSoft),
            '--footer-bg'    => self::hex($footerBg),
            '--footer-ink'   => self::hex($footerInk),
            '--footer-dim'   => self::hex($footerDim),
            // 语义色（错误固定红，不随品牌；info/success 用加深达标版）
            '--info'         => self::hex($brand),
            '--success'      => self::hex($success),
            '--warning'      => '#E6A23C',
            '--error'        => '#DC2626',
            // 首屏 / 遮罩
            '--hero-gradient' => $heroGradient,
            '--hero-glow'     => $heroGlow,
            '--hero-veil'     => $heroVeil,
            '--hero-kicker'   => self::hex($heroKicker),
            '--hero-trust'    => self::hex($accentRaw),
            '--scrim'         => $scrim,
            // 圆角
            '--radius-xs'    => '4px',
            '--radius-sm'    => $rSm . 'px',
            '--radius'       => $radius . 'px',
            '--radius-lg'    => $rLg . 'px',
            '--radius-xl'    => $rXl . 'px',
            '--radius-full'  => '999px',
            // 布局
            '--container'    => $container . 'px',
            '--font'         => $fontStack,
            '--font-en'      => $fontStackEn,
            '--sec-y'        => $sectionY,
            // P-STEP 18L-1 Typography：字号随 profile（rem）
            '--fs-display' => $fs['display'] . 'rem',
            '--fs-h1'      => $fs['h1'] . 'rem',
            '--fs-h2'      => $fs['h2'] . 'rem',
            '--fs-h3'      => $fs['h3'] . 'rem',
            '--fs-h4'      => $fs['h4'] . 'rem',
            '--fs-lg'      => $fs['lg'] . 'rem',
            '--fs-base'    => $fs['base'] . 'rem',
            '--fs-sm'      => $fs['sm'] . 'rem',
            '--fs-xs'      => $fs['xs'] . 'rem',
            '--fs-2xs'     => $fs['2xs'] . 'rem',
            '--fs-label'   => $fs['label'] . 'rem',
            '--fs-button'  => $fs['button'] . 'rem',
            // 行高（固定档位 + 随 profile 的 heading/body）
            '--lh-tight'   => '1.1',
            '--lh-snug'    => '1.25',
            '--lh-normal'  => '1.5',
            '--lh-relaxed' => '1.7',
            '--lh-heading' => $lhHeading,
            '--lh-body'    => $lhBody,
            // 字重
            '--fw-normal'   => '400',
            '--fw-medium'   => '500',
            '--fw-semibold' => '600',
            '--fw-bold'     => '700',
            // 字距
            '--ls-tight'  => '-.02em',
            '--ls-snug'   => '-.01em',
            '--ls-normal' => '0',
            '--ls-wide'   => '.02em',
            '--ls-caps'   => '.08em',
            // P-STEP 18L-1 Inverse：反白文字 / 边框 / ghost hover（深色面白字，不随深色模式覆盖）
            '--surface-inverse'      => self::DEEP,
            '--on-inverse'           => '#FFFFFF',
            '--on-inverse-soft'      => 'rgba(255,255,255,.9)',
            '--on-inverse-faint'     => 'rgba(255,255,255,.62)',
            '--inverse-line'         => 'rgba(255,255,255,.15)',
            '--inverse-line-strong'  => 'rgba(255,255,255,.4)',
            '--inverse-hover'        => 'rgba(255,255,255,.10)',
            '--inverse-hover-strong' => 'rgba(255,255,255,.20)',
            // 间距（4 点基准：sp-N = N×4px，覆盖 4–96px）
            '--sp-1' => '4px', '--sp-2' => '8px', '--sp-3' => '12px', '--sp-4' => '16px',
            '--sp-5' => '20px', '--sp-6' => '24px', '--sp-7' => '28px', '--sp-8' => '32px',
            '--sp-9' => '36px', '--sp-10' => '40px', '--sp-11' => '44px', '--sp-12' => '48px',
            '--sp-13' => '52px', '--sp-14' => '56px', '--sp-15' => '60px', '--sp-16' => '64px',
            '--sp-18' => '72px', '--sp-20' => '80px', '--sp-22' => '88px', '--sp-24' => '96px',
            // 动效
            '--motion-fast'  => '.1s cubic-bezier(.2,0,.2,1)',
            '--motion-base'  => '.16s cubic-bezier(.2,0,.2,1)',
            '--motion-slow'  => '.24s cubic-bezier(.2,0,.2,1)',
            // 位移（语义化动效距离）
            '--lift-press' => '-1px',  // 按钮按压
            '--lift-card' => '-2px',   // 可点卡片轻抬
            '--nudge' => '3px',        // 箭头推进
            '--rise' => '10px',        // 入场初位移
            // 阴影
            '--shadow-sm'      => $shadowSm,
            '--shadow'         => 'none',
            '--shadow-hover'   => $shadowHover,
            '--shadow-overlay' => $shadowOverlay,
        ];
    }

    /**
     * P-STEP 18L-1：按 typography profile 返回字号 scale（rem，纯函数）。
     *
     * @return array<string,float> display/h1/h2/h3/h4/lg/base/sm/xs/2xs/label/button
     */
    public static function typographyScale(string $profile): array
    {
        $scales = [
            'standard' => [
                'display' => 3.0, 'h1' => 2.75, 'h2' => 2.0, 'h3' => 1.5, 'h4' => 1.1875,
                'lg' => 1.125, 'base' => 1.0, 'sm' => 0.9375, 'xs' => 0.875,
                '2xs' => 0.8125, 'label' => 0.78, 'button' => 0.9375,
            ],
            'compact' => [
                'display' => 2.5, 'h1' => 2.25, 'h2' => 1.75, 'h3' => 1.375, 'h4' => 1.125,
                'lg' => 1.0625, 'base' => 0.9375, 'sm' => 0.875, 'xs' => 0.8125,
                '2xs' => 0.75, 'label' => 0.72, 'button' => 0.875,
            ],
            'editorial' => [
                'display' => 3.25, 'h1' => 3.0, 'h2' => 2.125, 'h3' => 1.625, 'h4' => 1.25,
                'lg' => 1.1875, 'base' => 1.0625, 'sm' => 1.0, 'xs' => 0.9375,
                '2xs' => 0.875, 'label' => 0.82, 'button' => 1.0,
            ],
        ];

        return $scales[$profile] ?? $scales['standard'];
    }

    /**
     * 把令牌映射渲染为 :root 内的 CSS 声明文本（供 Blade 内联，亦可用于测试 / 调试）。
     *
     * @param  array<string,string>  $tokens
     */
    public static function toCss(array $tokens): string
    {
        $out = '';
        foreach ($tokens as $name => $value) {
            $out .= $name . ':' . $value . ';';
        }

        return $out;
    }

    /**
     * 深色外观（[data-color-scheme="dark"]）下需要覆盖的语义令牌子集（P-STEP 18D Light/Dark/System）。
     *
     * 只返回与浅色不同的令牌：中性阶换成深色 elevated 体系；品牌 / 辅色 / 语义色在深底上
     * 提亮到与深色底互为 WCAG AA（链接 / 文字 / 图标可读）；浅底（*-soft）改半透明、focus
     * ring 加亮；实心 CTA 面不覆盖（沿用浅色品牌同色系派生面，白字在深底页面的饱和色块上依旧
     * 达标）。首屏 Hero 本就是深色品牌渐变，深色下天然协调，故不在此反转。纯函数，恒定输出。
     *
     * @param  array<string,mixed>  $settings  Setting::allCached() 形态（key => value）
     * @return array<string,string> 键为 CSS 变量名（含 -- 前缀），值为不含分号的 CSS 值
     */
    public static function darkOverrides(array $settings, array $themeTokens = []): array
    {
        $get = static function (string $key, $default) use ($settings, $themeTokens) {
            if (isset($settings[$key]) && trim((string) $settings[$key]) !== '') {
                return trim((string) $settings[$key]);
            }
            if (array_key_exists($key, $themeTokens) && trim((string) $themeTokens[$key]) !== '') {
                return trim((string) $themeTokens[$key]);
            }
            return $default;
        };

        $brandRaw  = self::hexToRgb((string) $get('theme_primary', self::DEFAULTS['brand']));
        $accentRaw = self::hexToRgb((string) $get('theme_accent', self::DEFAULTS['accent']));
        $deep      = self::hexToRgb(self::DEEP);

        // 深底上的文字 / 链接 / 图标色：向白提亮直到与深色底互为 AA。
        $dBrand  = self::lightenForContrast($brandRaw, $deep, self::AA_RATIO);
        $dAccent = self::lightenForContrast($accentRaw, $deep, self::AA_RATIO);
        $dError  = self::lightenForContrast(self::hexToRgb('#DC2626'), $deep, self::AA_RATIO);
        $dWarn   = self::lightenForContrast(self::hexToRgb('#E6A23C'), $deep, self::AA_RATIO);
        $dSuccess = self::lightenForContrast(self::hexToRgb('#16A34A'), $deep, self::AA_RATIO);

        return [
            // 深色中性阶（蓝灰 elevated 体系：bg 最深，surface 逐级抬升）
            '--hd-bg'        => 'rgba(11,18,32,.82)',
            '--bg'           => self::DEEP,
            '--surface'      => '#121A2B',
            '--surface-2'    => '#1B2536',
            '--surface-3'    => '#243044',
            '--ink'          => '#E8EDF4',
            '--ink-2'        => '#C6D0DD',
            '--ink-muted'    => '#97A4B6',
            '--ink-faint'    => '#6E7C8F',
            '--line'         => '#2B3850',
            '--line-soft'    => '#202C40',
            '--footer-bg'    => '#070C15',
            '--footer-ink'   => '#C6D0DD',
            '--footer-dim'   => '#8796A8',
            // 品牌（深底提亮：链接 / 文字 / 图标 / 点缀）
            '--brand'        => self::hex($dBrand),
            '--brand-dark'   => self::hex(self::mix($dBrand, '#FFFFFF', 0.14)),
            '--brand-active' => self::hex(self::mix($dBrand, '#000000', 0.10)),
            '--brand-soft'   => self::rgba($dBrand, 0.16),
            '--brand-on'     => '#0B1220',
            '--brand-ring'   => self::rgba($dBrand, 0.45),
            '--brand-bright' => self::hex(self::mix($dBrand, '#FFFFFF', 0.18)),
            // Action（深色下同样品牌同色系，P-STEP 18K）
            '--action'        => self::hex($dBrand),
            '--action-dark'   => self::hex(self::mix($dBrand, '#FFFFFF', 0.14)),
            '--action-active' => self::hex(self::mix($dBrand, '#000000', 0.10)),
            '--action-soft'   => self::rgba($dBrand, 0.16),
            '--action-on'     => '#0B1220',
            // 辅色（深底提亮）
            '--accent'        => self::hex($dAccent),
            '--accent-dark'   => self::hex(self::mix($dAccent, '#FFFFFF', 0.14)),
            '--accent-soft'   => self::rgba($dAccent, 0.16),
            '--accent-on'     => '#0B1220',
            '--accent-ring'   => self::rgba($dAccent, 0.45),
            '--accent-bright' => self::hex(self::mix($dAccent, '#FFFFFF', 0.18)),
            // CTA：实心面 / 白字沿用浅色品牌同色系派生（不覆盖），浅底转品牌半透明
            '--cta-soft'     => self::rgba($dBrand, 0.16),
            // 语义色（深底提亮；成功固定绿、错误固定红，不随品牌 / accent）
            '--info'         => self::hex($dBrand),
            '--success'      => self::hex($dSuccess),
            '--warning'      => self::hex($dWarn),
            '--error'        => self::hex($dError),
            // 首屏信任点缀随提亮色
            '--hero-trust'   => self::hex(self::mix($dAccent, '#FFFFFF', 0.10)),
            '--hero-kicker'  => self::hex(self::mix($dBrand, '#FFFFFF', 0.18)),
            // 深底阴影（更深、更柔，用边框分层）
            '--shadow-sm'      => '0 1px 2px rgba(0,0,0,.45)',
            '--shadow-hover'   => '0 12px 30px rgba(0,0,0,.50)',
            '--shadow-overlay' => '0 20px 48px rgba(0,0,0,.58)',
        ];
    }

    // ----------------------------------------------------------------
    // 颜色数学（纯函数）
    // ----------------------------------------------------------------

    /** @return array{0:int,1:int,2:int} */
    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = ltrim(self::DEFAULTS['brand'], '#');
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /** 非法颜色回退到给定默认，返回归一化后的 hex（含 #）。 */
    public static function normalizeHex(string $hex, string $fallback): string
    {
        $h = ltrim(trim($hex), '#');
        if (preg_match('/^[0-9a-fA-F]{6}$/', $h) || preg_match('/^[0-9a-fA-F]{3}$/', $h)) {
            return '#' . strtoupper($h);
        }

        return strtoupper($fallback);
    }

    /**
     * sRGB 线性插值：把 $a 向 $b 混合 $t（0=全 a，1=全 b）。
     *
     * @param  array{0:int,1:int,2:int}|string  $a
     * @param  array{0:int,1:int,2:int}|string  $b
     * @return array{0:int,1:int,2:int}
     */
    public static function mix($a, $b, float $t): array
    {
        $ca = is_string($a) ? self::hexToRgb($a) : $a;
        $cb = is_string($b) ? self::hexToRgb($b) : $b;
        $t = max(0.0, min(1.0, $t));
        $rgb = [];
        for ($i = 0; $i < 3; $i++) {
            $rgb[$i] = (int) round($ca[$i] * (1 - $t) + $cb[$i] * $t);
        }

        return $rgb;
    }

    /**
     * 把颜色逐步向黑加深，直到其与白色互为正文对比度 ≥ $ratio。
     *
     * 同一深度同时满足「该色作底 + 白字」与「该色作文字 / 图标 + 白底」两个方向，
     * 因为二者的对比度公式都以白色为一端：1.05 / (L + .05) ≥ ratio。
     *
     * @param  array{0:int,1:int,2:int}  $rgb
     * @return array{0:int,1:int,2:int}
     */
    public static function deepenForContrast(array $rgb, float $ratio = self::AA_RATIO): array
    {
        if (1.05 / (self::luminance($rgb) + 0.05) >= $ratio) {
            return $rgb;
        }

        for ($t = 0.03; $t <= 0.85; $t += 0.03) {
            $candidate = self::mix($rgb, '#000000', $t);
            if (1.05 / (self::luminance($candidate) + 0.05) >= $ratio) {
                return $candidate;
            }
        }

        return self::mix($rgb, '#000000', 0.85);
    }

    /**
     * 把颜色逐步向白提亮，直到其在深色底 $bg 上的正文对比度 ≥ $ratio（深色模式链接 / 文字用）。
     *
     * @param  array{0:int,1:int,2:int}  $rgb
     * @param  array{0:int,1:int,2:int}  $bg
     * @return array{0:int,1:int,2:int}
     */
    public static function lightenForContrast(array $rgb, array $bg, float $ratio = self::AA_RATIO): array
    {
        $bgLum = self::luminance($bg);
        if ((self::luminance($rgb) + 0.05) / ($bgLum + 0.05) >= $ratio) {
            return $rgb;
        }

        for ($t = 0.05; $t <= 0.90; $t += 0.03) {
            $candidate = self::mix($rgb, '#FFFFFF', $t);
            if ((self::luminance($candidate) + 0.05) / ($bgLum + 0.05) >= $ratio) {
                return $candidate;
            }
        }

        return self::mix($rgb, '#FFFFFF', 0.90);
    }

    /** @param array{0:int,1:int,2:int}|string $c */
    public static function hex($c): string
    {
        $rgb = is_string($c) ? self::hexToRgb($c) : $c;

        return sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);
    }

    /** @param array{0:int,1:int,2:int}|string $c */
    public static function rgba($c, float $alpha): string
    {
        $rgb = is_string($c) ? self::hexToRgb($c) : $c;

        return sprintf('rgba(%d,%d,%d,%s)', $rgb[0], $rgb[1], $rgb[2], rtrim(rtrim(sprintf('%.2f', $alpha), '0'), '.'));
    }

    /** 返回 "r,g,b" 通道串，供 CSS rgba(var(--x),.1) 形态拼接。 */
    public static function rgbString(string $hex): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);

        return "{$r},{$g},{$b}";
    }

    /** WCAG 相对亮度（0=黑，1=白）。 @param array{0:int,1:int,2:int}|string $hex */
    public static function luminance($hex): float
    {
        [$r, $g, $b] = is_string($hex) ? self::hexToRgb($hex) : $hex;
        $chan = static fn (float $v): float => $v <= 0.03928 ? $v / 12.92 : pow((($v + 0.055) / 1.055), 2.4);

        return 0.2126 * $chan($r / 255) + 0.7152 * $chan($g / 255) + 0.0722 * $chan($b / 255);
    }

    /**
     * 返回在给定底色上对比度更高的前景色（深底返白、浅底返深墨），用于按钮反白文字。
     *
     * @param array{0:int,1:int,2:int}|string $hex
     */
    public static function onColor($hex): string
    {
        $lum = self::luminance($hex);
        $withWhite = (1.0 + 0.05) / ($lum + 0.05);
        $withBlack = ($lum + 0.05) / (0.0 + 0.05);

        return $withWhite >= $withBlack ? '#FFFFFF' : '#1A1A1A';
    }

    private static function clampInt(int $v, int $min, int $max, int $fallback): int
    {
        if ($v < $min || $v > $max) {
            return $fallback;
        }

        return $v;
    }
}
