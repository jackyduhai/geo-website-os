<?php

namespace App\Support;

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

    private const VERSION_KEY = 'pagecache:version';
    private const KEY_PREFIX  = 'pagecache:html:';

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
        return (int) self::store()->rememberForever(self::VERSION_KEY, fn () => 1);
    }

    /** 版本号 +1：所有已缓存页面立即失效。 */
    public static function flush(): void
    {
        $store = self::store();
        $store->forever(self::VERSION_KEY, self::version() + 1);
    }

    /** 仅以「主机 + 规范路径」为键；UTM 等投放参数不产生重复副本。 */
    public static function keyFor(Request $request): string
    {
        $path = '/'.ltrim($request->path(), '/');

        return self::KEY_PREFIX.self::version().':'.sha1($request->getHost().$path);
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
