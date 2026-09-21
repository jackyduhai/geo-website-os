<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Redirect as RedirectModel;
use App\Support\SiteContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

/**
 * 旧站 URL 301 映射（旧站 ?cb-13-1.html → 新规范路径）
 */
class RedirectController extends Controller
{
    public function index(): View
    {
        return view('admin.governance.redirects', [
            'redirects' => RedirectModel::orderBy('hits', 'desc')->paginate(30),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        RedirectModel::create($data);

        return back()->with('success', '跳转规则已添加');
    }

    public function update(Request $request, RedirectModel $redirect): RedirectResponse
    {
        $redirect->update($this->validateData($request, $redirect));

        return back()->with('success', '跳转规则已更新');
    }

    public function destroy(RedirectModel $redirect): RedirectResponse
    {
        $redirect->delete();

        return back()->with('success', '已删除');
    }

    protected function validateData(Request $request, ?RedirectModel $except = null): array
    {
        // 同一站点内旧路径唯一（DB 有 unique(site_id, from_path)）。
        // 必须在验证层拦截重复，否则会以唯一约束 SQL 异常直接 500（UAT Bug#5）。
        $data = $request->validate([
            'from_path' => [
                'required', 'string', 'max:255',
                Rule::unique('redirects', 'from_path')
                    ->where(fn ($query) => $query->where('site_id', SiteContext::currentSiteId()))
                    ->ignore($except?->id),
            ],
            'to_path'   => ['required', 'string', 'max:255'],
            'code'      => ['required', 'integer', 'in:301,302'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'from_path.unique' => '该旧路径已存在跳转规则，同一站点内旧路径不能重复。',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
