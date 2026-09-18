<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Menu;
use App\Providers\AppServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * 导航菜单（主导航 + 页脚，同一套机制）：
 * - 固定栏目 / 固定页脚列通过 menus.key 覆盖层支持「改名 / 改链接 / 新窗 / 显示隐藏 /
 *   排序 / 恢复默认」；知识中心子项由内容分组驱动（dynamic），联系列由站点设置驱动
 *   （locked），这两类不接受链接覆盖，请到对应数据源修改；
 * - 固定结构之外的额外入口用自定义菜单项（key 为空）追加：
 *   主导航支持两级（parent_id 挂自定义一级、parent_key 挂固定一级），
 *   页脚支持挂到固定列（parent_key=ft-col-*）或作为「快捷入口」一级链接。
 */
class MenuController extends Controller
{
    public function index(): View
    {
        // 固定栏目蓝图（已叠加覆盖层），后台与前台共用同一结构
        $blueprint = AppServiceProvider::mainMenuBlueprint();
        $footerBlueprint = AppServiceProvider::footerBlueprint();
        // 自定义一级项（key 为空），带其二级子项
        $menus = Menu::whereNull('key')
            ->whereNull('parent_id')
            ->where(fn ($q) => $q->whereNull('parent_key')->orWhere('parent_key', ''))
            ->with(['children' => fn ($q) => $q->orderBy('sort')])
            ->orderBy('position')
            ->orderBy('sort')
            ->get();
        // 挂在固定一级栏目 / 固定页脚列下的自定义子项（parent_key）
        $anchored = Menu::whereNull('key')
            ->whereNotNull('parent_key')
            ->where('parent_key', '!=', '')
            ->orderBy('sort')
            ->get();
        $categories = Category::orderBy('sort')->get();

        return view('admin.structure.menus', compact('blueprint', 'footerBlueprint', 'menus', 'anchored', 'categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['is_active'] = $request->boolean('is_active'); // 勾选=启用，未勾选=停用
        $data['target'] = $request->integer('target');
        $data['key'] = null; // 新建表单只产生自定义追加项，固定栏目覆盖走 saveOverride
        $menu = Menu::create($data);
        AppServiceProvider::forgetNavCache();
        AuditLog::record('menu.created', '新建菜单项：' . $menu->label, [], 'menu', $menu->id);

        return back()->with('success', '菜单项已创建');
    }

    public function update(Request $request, Menu $menu): RedirectResponse
    {
        // 固定栏目覆盖项不允许用自定义表单改成任意链接
        abort_if($menu->key !== null, 404);

        // 已是父级（含二级子项）的菜单不允许再被挂到别的菜单下，系统只支持两级
        if (Menu::where('parent_id', $menu->id)->exists()) {
            $request->merge(['parent_ref' => '']);
        }

        $data = $this->validateData($request);
        $data['is_active'] = $request->boolean('is_active');
        $data['target'] = $request->integer('target');
        $menu->update($data);
        AppServiceProvider::forgetNavCache();

        return back()->with('success', '菜单项已更新');
    }

    public function destroy(Request $request, Menu $menu): RedirectResponse
    {
        // 固定栏目覆盖行：恢复默认即删除覆盖
        if ($menu->key !== null) {
            $key = $menu->key;
            $menu->delete();
            AppServiceProvider::forgetNavCache();
            AuditLog::record('menu.override.reset', '恢复固定导航栏目默认：' . $key, [], 'menu');

            return back()->with('success', '已恢复默认');
        }

        if (Menu::where('parent_id', $menu->id)->exists()) {
            return back()->with('error', '该菜单下还有二级子菜单，请先删除或转移子菜单');
        }

        $menu->delete();
        AppServiceProvider::forgetNavCache();

        return back()->with('success', '菜单项已删除');
    }

    /**
     * 保存对固定栏目 / 固定页脚节点（蓝图节点）的覆盖：
     * 改名 / 改链接 / 新窗 / 显隐 / 排序；留空名称或链接即恢复该项默认。
     */
    public function saveOverride(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'position'  => ['nullable', 'in:main,footer'],
            'key'       => ['required', 'string', 'max:80'],
            'label'     => ['nullable', 'string', 'max:60'],
            'url'       => ['nullable', 'string', 'max:255', 'regex:/^(\/|#|https?:\/\/|tel:|mailto:)/i'],
            'sort'      => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
            'target'    => ['nullable', 'boolean'],
        ]);

        $position = $data['position'] ?? 'main';
        $nodes = $this->blueprintNodes($position);
        $node = $nodes->firstWhere('key', $data['key']);
        abort_unless($node !== null, 404, '未知的导航栏目');

        $isColumn = $position === 'footer' && str_starts_with($data['key'], 'ft-col-');
        // 列标题、联系列锁定项、知识分组动态项不接受链接覆盖
        $acceptUrl = ! $isColumn && empty($node['locked']) && empty($node['dynamic']);

        $menu = Menu::firstOrNew(['key' => $data['key']]);
        $menu->position = $position;
        $menu->parent_id = null;
        $menu->parent_key = null;
        $menu->category_id = null;
        $menu->label = trim((string) $request->input('label', ''));   // 留空=恢复默认名称
        $menu->sort = $request->integer('sort');                      // 0=默认顺序
        $menu->is_active = $request->boolean('is_active');            // 勾选=显示，未勾选=隐藏
        if ($acceptUrl) {
            $menu->url = trim((string) $request->input('url', ''));   // 留空=恢复默认链接
            $menu->target = $request->boolean('target') ? 1 : 0;
        } else {
            $menu->url = null;
            $menu->target = 0;
        }
        $menu->save();

        AppServiceProvider::forgetNavCache();
        AuditLog::record('menu.override', '覆盖固定导航栏目：' . $data['key'], [
            'position' => $position,
            'label'    => $menu->label,
            'url'      => $menu->url,
            'is_active'=> $menu->is_active,
            'sort'     => $menu->sort,
        ], 'menu', $menu->id);

        return back()->with('success', '导航栏目已更新');
    }

    /**
     * 清除对固定栏目的覆盖，恢复默认名称 / 链接 / 显示 / 排序。
     */
    public function resetOverride(Request $request, string $key): RedirectResponse
    {
        Menu::where('key', $key)->delete();
        AppServiceProvider::forgetNavCache();
        AuditLog::record('menu.override.reset', '恢复固定导航栏目默认：' . $key, [], 'menu');

        return back()->with('success', '已恢复默认');
    }

    protected function validateData(Request $request): array
    {
        $data = $request->validate([
            'position'    => ['required', 'in:main,footer,mobile'],
            'parent_ref'  => ['nullable', 'string', 'max:100'],
            'label'       => ['required', 'string', 'max:60'],
            'url'         => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'sort'        => ['nullable', 'integer'],
            'target'      => ['nullable', 'integer', 'in:0,1'],
        ]);

        // parent_ref：""=一级；"key:<蓝图key>"=固定一级栏目/页脚列下；"id:<菜单id>"=自定义一级下
        $data['parent_id'] = null;
        $data['parent_key'] = null;
        $ref = trim((string) ($data['parent_ref'] ?? ''));
        unset($data['parent_ref']);
        if (str_starts_with($ref, 'key:')) {
            $key = substr($ref, 4);
            if (str_starts_with($key, 'ft-col-')) {
                // 挂到页脚固定列
                $colExists = collect(AppServiceProvider::footerBlueprint())->contains(fn ($c) => $c['key'] === $key);
                if ($colExists) {
                    $data['parent_key'] = $key;
                    $data['position'] = 'footer';
                }
            } else {
                $data['parent_key'] = $this->resolveMainParentKey($key);
                if ($data['parent_key'] !== null) {
                    $data['position'] = 'main';
                }
            }
        } elseif (str_starts_with($ref, 'id:')) {
            $data['parent_id'] = (int) substr($ref, 3);
        }

        // parent_id 必须是「自定义一级菜单」：无 key、自身无父、且属于主导航
        if (! empty($data['parent_id'])) {
            $parent = Menu::whereNull('key')->find($data['parent_id']);
            $validParent = $parent
                && $parent->parent_id === null
                && ($parent->parent_key === null || $parent->parent_key === '')
                && in_array($parent->position, ['main', 'mobile'], true);
            if ($validParent) {
                $data['position'] = 'main';
            } else {
                $data['parent_id'] = null;
            }
        }

        // 主导航二级项强制 main；页脚挂列项强制 footer（上面已设置）；页脚只支持一级或挂列
        if ($data['parent_key'] !== null && ! str_starts_with((string) $data['parent_key'], 'ft-col-')) {
            $data['position'] = 'main';
        }

        $data['sort'] = $request->integer('sort');

        return $data;
    }

    /**
     * 蓝图全部节点（含子项 / 列与列内项），用于校验覆盖 key。
     */
    protected function blueprintNodes(string $position): Collection
    {
        if ($position === 'footer') {
            return collect(AppServiceProvider::footerBlueprint())
                ->flatMap(fn ($c) => collect([[
                    'key' => $c['key'], 'locked' => false, 'dynamic' => false,
                ]])->concat($c['items']));
        }

        return collect(AppServiceProvider::mainMenuBlueprint())
            ->flatMap(fn ($t) => collect([$t])->concat($t['children'] ?? []));
    }

    /**
     * 校验 parent_key 属于主导航蓝图一级栏目；空串归一为 null。
     */
    protected function resolveMainParentKey(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }
        $exists = collect(AppServiceProvider::mainMenuBlueprint())->contains(fn ($t) => $t['key'] === $key);

        return $exists ? $key : null;
    }
}
