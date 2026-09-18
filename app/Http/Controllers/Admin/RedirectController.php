<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Redirect as RedirectModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
        $redirect->update($this->validateData($request));

        return back()->with('success', '跳转规则已更新');
    }

    public function destroy(RedirectModel $redirect): RedirectResponse
    {
        $redirect->delete();

        return back()->with('success', '已删除');
    }

    protected function validateData(Request $request): array
    {
        $data = $request->validate([
            'from_path' => ['required', 'string', 'max:255'],
            'to_path'   => ['required', 'string', 'max:255'],
            'code'      => ['required', 'integer', 'in:301,302'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
