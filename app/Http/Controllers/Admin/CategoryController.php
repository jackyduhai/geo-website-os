<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 栏目结构管理（最多两级）。任何写入后清掉前台导航缓存。
 */
class CategoryController extends Controller
{
    public function index(): View
    {
        $categories = Category::with('children')
            ->whereNull('parent_id')
            ->orderBy('sort')
            ->get();

        return view('admin.structure.categories', compact('categories'));
    }

    public function create(): View
    {
        return view('admin.structure.category-form', [
            'category'    => new Category(['is_nav' => true, 'is_active' => true, 'is_index' => false]),
            'roots'       => Category::whereNull('parent_id')->orderBy('sort')->get(),
            'iconOptions' => config('icons'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $category = Category::create($data);
        $this->forgetNav();
        AuditLog::record('category.created', '新建栏目：' . $category->name, [], 'category', $category->id);

        return redirect()->route('admin.categories.index')->with('success', '栏目已创建');
    }

    public function edit(Category $category): View
    {
        return view('admin.structure.category-form', [
            'category'    => $category,
            'roots'       => Category::whereNull('parent_id')->where('id', '!=', $category->id)->orderBy('sort')->get(),
            'iconOptions' => config('icons'),
        ]);
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $this->validateData($request, $category);
        $category->update($data);
        $this->forgetNav();
        AuditLog::record('category.updated', '更新栏目：' . $category->name, [], 'category', $category->id);

        return redirect()->route('admin.categories.index')->with('success', '栏目已更新');
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->children()->exists()) {
            return back()->with('error', '该栏目下还有子栏目，请先处理子栏目');
        }
        if ($category->contents()->exists()) {
            return back()->with('error', '该栏目下还有内容，请先移动或删除内容');
        }
        $name = $category->name;
        $category->delete();
        $this->forgetNav();
        AuditLog::record('category.deleted', '删除栏目：' . $name, [], 'category', $category->id);

        return back()->with('success', '栏目已删除');
    }

    public function toggle(Category $category, Request $request): RedirectResponse
    {
        $field = $request->get('field', 'is_active');
        if (! in_array($field, ['is_active', 'is_nav', 'is_index'], true)) {
            return back()->with('error', '非法字段');
        }
        $category->update([$field => ! $category->{$field}]);
        $this->forgetNav();

        return back()->with('success', '状态已切换');
    }

    protected function validateData(Request $request, ?Category $except = null): array
    {
        $data = $request->validate([
            'parent_id'    => ['nullable', 'exists:categories,id'],
            'name'         => ['required', 'string', 'max:60'],
            'slug'         => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:categories,slug' . ($except ? ',' . $except->id : '')],
            'icon'         => ['nullable', 'in:' . implode(',', array_keys(config('icons')))],
            'type'         => ['required', 'in:list,page,product_list,external'],
            'description'  => ['nullable', 'string', 'max:1000'],
            'seo_title'    => ['nullable', 'string', 'max:70'],
            'seo_desc'     => ['nullable', 'string', 'max:180'],
            'sort'         => ['nullable', 'integer', 'min:0'],
            'is_nav'       => ['nullable', 'boolean'],
            'is_active'    => ['nullable', 'boolean'],
            'is_index'     => ['nullable', 'boolean'],
            'external_url' => ['nullable', 'string', 'max:255'],
        ]);
        $data['is_nav'] = $request->boolean('is_nav');
        $data['is_active'] = $request->boolean('is_active');
        $data['is_index'] = $request->boolean('is_index');
        $data['icon'] = $request->filled('icon') ? $request->input('icon') : null;
        $data['sort'] = $data['sort'] ?? 0;

        return $data;
    }

    protected function forgetNav(): void
    {
        // 统一走导航缓存失效（nav.tree / main.menu / blueprint / footer.extra），
        // 旧实现只 forget('nav_tree') 与真实键 'nav.tree' 不符，等于没清。
        \App\Providers\AppServiceProvider::forgetNavCache();
    }
}
