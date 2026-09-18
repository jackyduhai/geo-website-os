<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Fact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 事实库管理：全站唯一事实源，llms.txt / JSON-LD / 页面事实块三处共用
 */
class FactController extends Controller
{
    public function index(Request $request): View
    {
        $query = Fact::orderBy('group')->orderBy('sort');
        if ($request->get('gap') !== null) {
            $query->where('is_public', false);
        }

        return view('admin.governance.facts', [
            'facts'  => $query->get(),
            'groups' => Fact::select('group')->distinct()->pluck('group'),
        ]);
    }

    public function create(): View
    {
        return view('admin.governance.fact-form', ['fact' => new Fact(['is_public' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $fact = Fact::create($this->validateData($request));
        Fact::flushMemo();
        AuditLog::record('fact.created', '新增事实：' . $fact->label, [], 'fact', $fact->id);

        return redirect()->route('admin.facts.index')->with('success', '事实已录入');
    }

    public function edit(Fact $fact): View
    {
        return view('admin.governance.fact-form', compact('fact'));
    }

    public function update(Request $request, Fact $fact): RedirectResponse
    {
        $fact->update($this->validateData($request));
        Fact::flushMemo();
        AuditLog::record('fact.updated', '更新事实：' . $fact->label, [], 'fact', $fact->id);

        return redirect()->route('admin.facts.index')->with('success', '事实已更新');
    }

    public function destroy(Fact $fact): RedirectResponse
    {
        $fact->delete();
        Fact::flushMemo();

        return back()->with('success', '事实已删除');
    }

    protected function validateData(Request $request): array
    {
        $data = $request->validate([
            'group'       => ['required', 'string', 'max:40'],
            'key'         => ['required', 'string', 'max:80'],
            'label'       => ['required', 'string', 'max:120'],
            'value'       => ['nullable', 'string'],
            'unit'        => ['nullable', 'string', 'max:20'],
            'source'      => ['nullable', 'string', 'max:255'],
            'source_url'  => ['nullable', 'string', 'max:255'],
            'is_public'   => ['nullable', 'boolean'],
            'reviewed_at' => ['nullable', 'date'],
            'review_due'  => ['nullable', 'date'],
            'sort'        => ['nullable', 'integer'],
        ]);
        $data['is_public'] = $request->boolean('is_public');
        $data['sort'] = $data['sort'] ?? 0;

        return $data;
    }
}
