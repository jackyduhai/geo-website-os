<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Site;
use App\Support\RequestScopedState;
use App\Support\SiteContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * 站点管理（P-STEP 17A）。
 *
 * Site 是多站租户根，本身不使用 BelongsToSite（无 site_id 列、无全局 Scope），
 * 因此这里是后台少数显式跨站查询的地方，且整组路由由 EnsureSuperAdmin 把守：
 * 仅超级管理员可列出 / 新建 / 修改 / 删除站点。
 *
 * 站点下的业务数据（Content / Entity / Setting …）一律仍走 SiteScope，
 * 本控制器不修改任何站点私有资源的隔离契约。
 */
class SiteController extends Controller
{
    /**
     * 系统日志类表：记录的是操作痕迹而非业务数据，不作为删除站点的阻塞项。
     */
    private const SYSTEM_TABLES = ['audit_logs', 'sync_logs'];

    public function index(): View
    {
        $sites = Site::orderBy('id')->get()->map(function (Site $site): Site {
            $counts = $this->resourceCounts($site->id);
            $site->setAttribute('content_count', $counts['contents'] ?? 0);
            $site->setAttribute('entity_count', $counts['entities'] ?? 0);
            $site->setAttribute('resource_total', array_sum($counts));

            return $site;
        });

        return view('admin.sites.index', ['sites' => $sites]);
    }

    public function create(): View
    {
        return view('admin.sites.form', [
            'site' => new Site(['status' => Site::STATUS_ACTIVE, 'is_default' => false]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $makeDefault = $request->boolean('is_default');

        $site = DB::transaction(function () use ($data, $makeDefault): Site {
            $site = Site::create($data);
            if ($makeDefault) {
                $this->promoteDefault($site);
            }

            return $site;
        });

        AuditLog::record('site.created', '新建站点：' . $site->name, [], 'site', $site->id);

        return redirect()->route('admin.sites.index')->with('success', '站点已创建');
    }

    public function edit(Site $site): View
    {
        return view('admin.sites.form', compact('site'));
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        $data = $this->validateData($request, $site);

        // 默认站不允许在编辑表单里被取消默认；要更换默认站，请对另一站点「设为默认」。
        $makeDefault = $site->is_default || $request->boolean('is_default');

        DB::transaction(function () use ($site, $data, $makeDefault): void {
            $site->update($data);
            if ($makeDefault) {
                $this->promoteDefault($site);
            }
        });

        AuditLog::record('site.updated', '更新站点：' . $site->name, [], 'site', $site->id);

        return redirect()->route('admin.sites.index')->with('success', '站点已更新');
    }

    public function destroy(Request $request, Site $site): RedirectResponse
    {
        if ($site->is_default || $site->slug === Site::DEFAULT_SLUG) {
            return back()->with('error', '默认站点不可删除');
        }

        $counts = $this->resourceCounts($site->id);
        if (array_sum($counts) > 0) {
            $detail = collect($counts)->map(fn (int $n, string $t) => "{$t}（{$n}）")->implode('、');

            return back()->with('error', '该站点下仍有数据，请先清空或迁移后再删除：' . $detail);
        }

        $name = $site->name;
        $site->delete();

        // 删除的正是后台当前管理站点时，回到默认上下文，避免 session 指向不存在的站点。
        if ($request->session()->get('admin_site_slug') === $site->slug) {
            $request->session()->forget('admin_site_slug');
        }

        AuditLog::record('site.deleted', '删除站点：' . $name, [], 'site', $site->id);

        return back()->with('success', '站点已删除');
    }

    /**
     * 把指定站点提升为唯一默认站点（partial unique index sites_is_default_unique 在 DB 层兜底）。
     */
    public function makeDefault(Site $site): RedirectResponse
    {
        DB::transaction(fn () => $this->promoteDefault($site));

        AuditLog::record('site.default', '设置默认站点：' . $site->name, [], 'site', $site->id);

        return back()->with('success', '默认站点已切换为：' . $site->name);
    }

    /**
     * 顶部站点切换器：记录后台当前管理站点到 session（仅超管可调用，见路由中间件）。
     */
    public function switchSite(Request $request): RedirectResponse
    {
        $data = $request->validate(
            ['site_id' => ['required', 'exists:sites,id']],
            ['site_id.required' => '请选择要切换的站点', 'site_id.exists' => '所选站点不存在'],
        );

        $site = Site::findOrFail($data['site_id']);
        $request->session()->put('admin_site_slug', $site->slug);

        // 立即在本请求复位一次请求级状态，使重定向目标页直接落在新站点上下文。
        SiteContext::setSite($site);
        RequestScopedState::reapply();

        return back()->with('success', '当前管理站点：' . $site->name);
    }

    /**
     * 把某站点设为唯一默认站。
     */
    private function promoteDefault(Site $site): void
    {
        DB::table('sites')->where('id', '!=', $site->id)->update(['is_default' => false]);
        DB::table('sites')->where('id', $site->id)->update(['is_default' => true]);
        $site->is_default = true;
    }

    /**
     * 表单校验 + 输入规范化。编辑时 slug 为稳定标识（session 切站、默认判定都依赖它），不允许修改。
     */
    private function validateData(Request $request, ?Site $site = null): array
    {
        $request->merge([
            'domain' => $this->normalizeDomain($request->input('domain')),
            'slug' => $site ? $site->slug : $this->normalizeSlug($request->input('slug')),
        ]);

        $data = $request->validate(
            [
                'name' => ['required', 'string', 'max:120'],
                'slug' => [
                    'required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                    'unique:sites,slug' . ($site ? ',' . $site->id : ''),
                ],
                'domain' => [
                    'nullable', 'string', 'max:255',
                    'unique:sites,domain' . ($site ? ',' . $site->id : ''),
                ],
                'description' => ['nullable', 'string', 'max:5000'],
                'logo' => ['nullable', 'string', 'max:255'],
                'status' => ['required', 'in:' . implode(',', [
                    Site::STATUS_ACTIVE, Site::STATUS_INACTIVE, Site::STATUS_MAINTENANCE,
                ])],
            ],
            [
                'name.required' => '请填写站点名称',
                'slug.required' => '请填写站点标识',
                'slug.regex' => '站点标识只能包含小写字母、数字和连字符（如 site-a）',
                'slug.unique' => '该站点标识已被使用',
                'domain.unique' => '该域名已绑定到其他站点，一个域名只能对应一个站点',
                'status.in' => '站点状态非法',
            ],
        );

        // 编辑场景下 slug 只读，忽略任何随表单提交的 slug，保证标识稳定。
        if ($site) {
            unset($data['slug']);
        }

        return $data;
    }

    /**
     * 剥离协议 / 端口 / 路径，仅保留裸域名并小写。
     * 例：https://www.example.com:8443/foo → www.example.com
     */
    private function normalizeDomain(mixed $value): ?string
    {
        $domain = trim((string) $value);
        if ($domain === '') {
            return null;
        }

        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = explode('/', (string) $domain, 2)[0];
        $domain = explode(':', (string) $domain, 2)[0];
        $domain = strtolower(trim((string) $domain));

        return $domain === '' ? null : $domain;
    }

    private function normalizeSlug(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    /**
     * 统计某站点下各站点私有表的数据量（动态扫描含 site_id 列的业务表）。
     * 用于删除保护：站点仍有任何业务数据时禁止硬删。
     */
    private function resourceCounts(int $siteId): array
    {
        $counts = [];

        foreach (Schema::getTableListing() as $table) {
            if ($table === 'sites' || in_array($table, self::SYSTEM_TABLES, true)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'site_id')) {
                continue;
            }

            $count = DB::table($table)->where('site_id', $siteId)->count();
            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        return $counts;
    }
}
