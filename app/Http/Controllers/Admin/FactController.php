<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Fact;
use App\Support\Localization\LocaleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * 事实库管理：全站唯一事实源，llms.txt / JSON-LD / 页面事实块三处共用
 *
 * ── 翻译关系维护（20G-3）────────────────────────────────────────
 * 运营视角的界面语义是「一条事实有几种语言」，而不是「有几条 fact 记录」：
 *
 *   列表  按 `key` 聚合，一行一个事实，显示各语言的 label/value 摘要，
 *         缺失的语言显式标「未翻译」—— 让缺口可见，
 *         而不是让人以为该事实只有一种语言。
 *   编辑  按语言区块维护 label / value；
 *         共享字段（是否公开 / 分组 / 排序 / 依据 / 责任人 / 复核日期）
 *         只维护一次，改后由 Translatable 的共享列同步机制自动同步到
 *         其它语言行 —— 避免「中文公开、英文未公开」的口径分裂。
 *   归组  translation_group 由系统按 `key` 派生（Fact::groupForKey），
 *         **不接受人工输入**：运营人员不需要理解 group id 的存在。
 */
class FactController extends Controller
{
    public function index(Request $request): View
    {
        $query = Fact::orderBy('group')->orderBy('sort');
        if ($request->get('gap') !== null) {
            $query->where('is_public', false);
        }
        $rows = $query->get();

        /**
         * 按 key 聚合成「一个事实 × 多种语言」。
         * 默认语言行作为展示基准（权威行），缺失时退回该 key 下第一条。
         */
        $default = LocaleRegistry::default();
        $grouped = $rows->groupBy('key')->map(function (Collection $langs) use ($default): array {
            /** @var Fact|null $anchor */
            $anchor = $langs->firstWhere('locale', $default) ?? $langs->first();

            $byLocale = [];
            foreach ($langs as $row) {
                $byLocale[$row->locale] = $row;
            }

            return [
                'anchor'  => $anchor,
                'rows'    => $byLocale,
                'missing' => array_values(array_diff(LocaleRegistry::supported(), array_keys($byLocale))),
            ];
        })->sortBy(fn (array $g) => (string) ($g['anchor']->group ?? '').(string) $g['anchor']->sort);

        return view('admin.governance.facts', [
            'facts'   => $grouped->values(),
            'groups'  => Fact::query()->select('group')->distinct()->pluck('group'),
            'locales' => LocaleRegistry::supported(),
        ]);
    }

    public function create(): View
    {
        return view('admin.governance.fact-form', [
            'fact'    => new Fact(['is_public' => true, 'locale' => LocaleRegistry::default()]),
            'locales' => LocaleRegistry::supported(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['translation_group'] = Fact::groupForKey($data['key']);

        $fact = Fact::create($data);
        Fact::flushMemo();
        AuditLog::record('fact.created', '新增事实：'.$fact->label, [], 'fact', $fact->id);

        return redirect()->route('admin.facts.index')->with('success', '事实已录入');
    }

    public function edit(Fact $fact): View
    {
        return view('admin.governance.fact-form', [
            'fact'     => $fact,
            'locales'  => LocaleRegistry::supported(),
            // 同 key 的其它语言行 —— 编辑页按语言区块一次性呈现
            'siblings' => $fact->translationRows(),
        ]);
    }

    /**
     * 更新：单条语言行。
     *
     * 共享列（is_public / group / sort / source / owner / reviewed_at /
     * review_due / unit / source_url / key）由 Translatable 的
     * `syncSharedColumns()` 从默认语言权威行单向同步，此处不重复处理。
     */
    public function update(Request $request, Fact $fact): RedirectResponse
    {
        $fact->update($this->validateData($request));
        Fact::flushMemo();
        AuditLog::record('fact.updated', '更新事实：'.$fact->label, [], 'fact', $fact->id);

        return redirect()->route('admin.facts.index')->with('success', '事实已更新');
    }

    /**
     * 新增某语言的翻译行。
     *
     * 运营人员在编辑页点「补英文」→ 这里按 `key` 派生 group 建en 行，
     * 共享列从中文权威行复制（Translatable::createTranslation 的语义）。
     */
    public function storeTranslation(Request $request, Fact $fact): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', 'in:'.implode(',', LocaleRegistry::supported())],
            'label'  => ['required', 'string', 'max:120'],
            'value'  => ['nullable', 'string'],
        ]);

        $exists = Fact::query()
            ->where('key', $fact->key)
            ->where('locale', $data['locale'])
            ->exists();
        if ($exists) {
            return back()->with('error', '该语言的事实行已存在，请直接编辑');
        }

        $row = $fact->createTranslation($data['locale'], [
            'label' => $data['label'],
            'value' => $data['value'] ?? null,
        ]);
        Fact::flushMemo();
        AuditLog::record(
            'fact.translation_created',
            '补录 '.$data['locale'].' 事实：'.$data['label'],
            [],
            'fact',
            $row->id
        );

        return back()->with('success', '已补录 '.$data['locale'].' 翻译');
    }

    public function destroy(Fact $fact): RedirectResponse
    {
        /**
         * Translatable 的 deleting 钩子会级联删除同 translation_group 的
         * 其它语言行 —— 这是有意的：删掉中文权威行却留下英文行，
         * 会造成「中文看不到了、英文还在」的口径泄漏（BUG-20B-002 同款）。
         */
        $fact->delete();
        Fact::flushMemo();

        return back()->with('success', '事实已删除（含各语言行）');
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
            'locale'      => ['nullable', 'string', 'in:'.implode(',', LocaleRegistry::supported())],
        ]);

        $data['is_public'] = $request->boolean('is_public');
        $data['sort'] = $data['sort'] ?? 0;
        $data['locale'] = $data['locale'] ?? LocaleRegistry::default();

        return $data;
    }
}
