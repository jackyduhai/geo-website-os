<?php

namespace App\Support;

use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
/**
 * 前台整页响应缓存（服务端静态化）。
 * ------------------------------------------------------------------
 * 设计要点：
 *  - 固定使用 file 存储（与默认 CACHE_STORE 解耦），HTML 落盘，命中即跳过
 *    Blade 渲染与查库，把 TTFB 从「动态渲染」降到「读文件 + 少量回填」。
 *  - 只缓存「匿名外壳」：@csrf token 与首次归因（landing/referer/utm）是
 *    按会话变化的内容，缓存前替换成占位符，命中后用当前会话值回填，
 *    因此一份缓存可服务所有访客，且不会串号 / 不会让 CSRF 失效。
 *  - 版本化失效：内容、栏目、Banner、菜单、装修、设置等任一前台相关模型
 *    保存/删除时调用 flush() 使版本号 +1，旧缓存整体作废（无需逐页删除），
 *    保证后台改完前台立刻生效、不发旧页面。
 *  - 缓存的是 SSR 直出的完整 HTML，搜索引擎与 AI 爬虫命中时同样拿到完整内容，
 *    对 SEO/GEO 只有加速、没有任何内容损失。
 */
class PageCache
{
    /** 缓存有效期（秒）：发布即失效为主，TTL 仅作自愈兜底。 */
    public const TTL = 21600; // 6 小时

    private const KEY_PREFIX  = 'pagecache:html:';
    private const PATHVER_PREFIX = 'pagecache:pathver:';

    /** CSRF token、CSP nonce 与归因隐藏字段的占位符（纯大写，不会与正文冲突）。 */
    public const P_CSRF = '{{PC_CSRF_TOKEN}}';
    public const P_CSP  = '{{PC_CSP_NONCE}}';

    private const ATTR_FIELDS = [
        'landing_url'  => 'PC_ATTR_LANDING',
        'referer'      => 'PC_ATTR_REFERER',
        'utm_source'   => 'PC_ATTR_UTM_SOURCE',
        'utm_medium'   => 'PC_ATTR_UTM_MEDIUM',
        'utm_campaign' => 'PC_ATTR_UTM_CAMPAIGN',
        'utm_term'     => 'PC_ATTR_UTM_TERM',
        'utm_content'  => 'PC_ATTR_UTM_CONTENT',
    ];

    /** 强制使用文件存储，避免与数据库/数组缓存互相影响。 */
    private static function store(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store('file');
    }

    public static function version(): int
    {
        return (int) self::store()->rememberForever(SiteCacheKey::pagecacheVersion(), fn () => 1);
    }

    /** 版本号 +1：当前站点所有已缓存页面立即失效。 */
    public static function flush(): void
    {
        $store = self::store();
        $store->forever(SiteCacheKey::pagecacheVersion(), self::version() + 1);
    }

    /**
     * 以「主机(含端口) + 规范路径」为键；UTM 等投放参数不产生重复副本。
     *
     * 必须包含端口：HTTP origin = host + port，同一主机不同端口（本地多实例并排
     * 验证、或同机非标端口反代的不同站点）属于不同来源。若只用 getHost()，两个
     * 端口的实例会命中同一份整页 shell，导致 Demo 站首页返回空站正文、canonical
     * 指向另一端口（P-STEP 18B 实测）。标准 80/443 端口 HTTP_HOST 不含端口，
     * 生产按域名分区的行为不变。
     */
    public static function keyFor(Request $request): string
    {
        $path = '/'.ltrim($request->path(), '/');
        $pathVersion = (int) (self::pathVersions(self::hostToken($request))[$path] ?? 0);

        return self::KEY_PREFIX.self::version().':'.$pathVersion.':'
            .sha1($request->getHttpHost().$path);
    }

    /** 主机标识（含端口）：path 版本映射按主机分区，避免多站 / 多端口串用。 */
    private static function hostToken(Request $request): string
    {
        return sha1($request->getHttpHost());
    }

    /** @return array<string,int> 该主机各 path 的失效版本号。 */
    private static function pathVersions(string $hostToken): array
    {
        return (array) self::store()->get(self::PATHVER_PREFIX.$hostToken, []);
    }

    /**
     * 使单个公开 path 的整页缓存失效（path 版本 +1，旧 key 不再被命中）。
     * 用于 Block / 排序 / 显隐等只影响某一页面的改动，而非整站 flush。
     */
    public static function forgetPath(string $path, ?string $host = null): void
    {
        $host = $host ?? request()?->getHttpHost();
        if ($host === null) {
            return; // 无 HTTP 主机上下文（纯 CLI）时无法定位，跳过页面级失效
        }

        $ht = sha1($host);
        $versions = self::pathVersions($ht);
        $versions[$path] = ((int) ($versions[$path] ?? 0)) + 1;
        self::store()->forever(self::PATHVER_PREFIX.$ht, $versions);
    }

    /**
     * 使某个 Page（当前语言版本对应 path）的整页缓存失效。
     * 中文 → /{slug}，英文 → /en/{slug}；首页 slug 为空 → / 或 /en。
     */
    public static function forgetPage(Page $page): void
    {
        $prefix = $page->locale === 'en' ? '/en' : '';
        $path = $page->slug
            ? $prefix.'/'.ltrim((string) $page->slug, '/')
            : ($prefix !== '' ? $prefix : '/');

        self::forgetPath($path);
    }

    /**
     * 模板结构变更影响所有使用该模板的页面，且槽位映射无法逐 path 枚举，
     * 直接整站版本 +1（模板改动罕见，可接受）。
     */
    public static function forgetTemplate(): void
    {
        self::flush();
    }

    public static function get(Request $request): ?string
    {
        $shell = self::store()->get(self::keyFor($request));

        return is_string($shell) ? self::personalize($shell, $request) : null;
    }

    public static function put(Request $request, string $html): void
    {
        self::store()->put(self::keyFor($request), self::toShell($html), self::TTL);
    }

    /**
     * 把响应 HTML 中按会话变化的字段统一替换成占位符，得到可共享的「匿名外壳」。
     * 用正则按字段名定位 input 并重写其 value，无论原值是否为空都能正确占位。
     */
    public static function toShell(string $html): string
    {
        $html = self::placeholdField($html, '_token', self::P_CSRF);

        foreach (self::ATTR_FIELDS as $field => $ph) {
            $html = self::placeholdField($html, $field, '{{'.$ph.'}}');
        }

        // CSP nonce 每请求变化：缓存外壳统一占位，命中后用当前请求 nonce 回填，避免钉死
        $html = preg_replace('#\snonce=("[^"]*"|\'[^\']*\')#', ' nonce="'.self::P_CSP.'"', $html) ?? $html;

        return $html;
    }

    /** 命中后用当前会话的 token / 归因值回填占位符。 */
    public static function personalize(string $shell, Request $request): string
    {
        $session = $request->session();
        $attr = $session->get('attr', []);

        $replace = [
            self::P_CSRF => e($session->token()),
            self::P_CSP  => (string) $request->attributes->get('csp_nonce', ''),
        ];
        foreach (self::ATTR_FIELDS as $field => $ph) {
            $replace['{{'.$ph.'}}'] = e((string) ($attr[$field] ?? ''));
        }

        return strtr($shell, $replace);
    }

    /**
     * 重写指定 <input name=$field> 的 value 为占位符，兼容属性先后顺序。
     */
    private static function placeholdField(string $html, string $field, string $placeholder): string
    {
        $pattern = '#(<input\b(?=[^>]*\bname=(["\'])'.preg_quote($field, '#').'\2)[^>]*?\bvalue=)(["\'])[^"\']*\3#is';

        return preg_replace($pattern, '${1}${3}'.$placeholder.'${3}', $html, 1) ?? $html;
    }
}
