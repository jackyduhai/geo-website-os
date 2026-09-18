<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Group;
use App\Providers\AppServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 栏目内分组管理
 */
class GroupController extends Controller
{
    public function index(): View
    {
        $groups = Group::with('category')->orderBy('sort')->get();
        $categories = Category::orderBy('sort')->get();

        return view('admin.structure.groups', compact('groups', 'categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $group = Group::create($data);
        AuditLog::record('group.created', '新建分组：' . $group->name, [], 'group', $group->id);
        $this->afterWrite();

        return back()->with('success', '分组已创建');
    }

    public function update(Request $request, Group $group): RedirectResponse
    {
        $group->update($this->validateData($request));
        $this->afterWrite();

        return back()->with('success', '分组已更新');
    }

    public function destroy(Group $group): RedirectResponse
    {
        if ($group->contents()->exists()) {
            return back()->with('error', '分组下还有内容，请先处理');
        }
        $group->delete();
        $this->afterWrite();

        return back()->with('success', '分组已删除');
    }

    /** 分组变更会影响知识子栏目、主菜单、sitemap/llms，统一清缓存 */
    protected function afterWrite(): void
    {
        Group::flushKnowledgeMemo();
        AppServiceProvider::forgetNavCache();
    }

    protected function validateData(Request $request): array
    {
        $data = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'name'        => ['required', 'string', 'max:60'],
            'slug'        => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort'        => ['nullable', 'integer'],
            'is_active'   => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['sort'] = $data['sort'] ?? 0;

        return $data;
    }
}
