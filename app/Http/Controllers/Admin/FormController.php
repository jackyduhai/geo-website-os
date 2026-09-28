<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Support\Forms\FieldTypeRegistry;
use App\Support\Localization\LocaleRegistry;
use App\Support\PageCache;
use App\Support\SiteContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 后台表单管理（P-STEP 18H-2，D5）。
 * --------------------------------------------------
 *  - Forms：CRUD + 启停；默认 contact 表单不可删除；
 *  - FormField：逻辑字段（form_id,name）跨 locale 结构一致，本控制器在创建 /
 *    更新时为每个 locale 同步结构列，展示列（label/placeholder/help/options）
 *    按 locale 维护；
 *  - Submissions：只读（完整事实），Inquiry 投影仍由「客户留言」展示。
 *
 * 表单结构变化影响前台 HTML，统一 PageCache::flush()。
 */
class FormController extends Controller
{
    // ---------------- Forms ----------------

    public function index()
    {
        $forms = Form::withCount(['fields', 'submissions'])
            ->orderBy('id')->paginate(20);

        return view('admin.forms.index', compact('forms'));
    }

    public function create()
    {
        $form = new Form([
            'status'           => Form::STATUS_ENABLED,
            'honeypot_enabled' => true,
            'notification_channels' => 'email',
        ]);

        return view('admin.forms.form', compact('form'));
    }

    public function store(Request $request)
    {
        $form = Form::create($this->validateForm($request));

        AuditLog::record('form.store', "创建表单：{$form->name}", [], 'Form', $form->id);

        return redirect()->route('admin.forms.edit', $form)
            ->with('success', '表单已创建，可在下方添加字段。');
    }

    public function edit(Form $form)
    {
        return view('admin.forms.form', compact('form'));
    }

    public function update(Request $request, Form $form)
    {
        $auditAllowed = ['name', 'slug', 'title', 'status', 'consent_required', 'honeypot_enabled',
            'notification_enabled', 'notification_channels', 'notification_recipients'];
        $auditBefore = $form->only($auditAllowed);

        $form->update($this->validateForm($request));

        AuditLog::recordChange('form.update', 'Form', $form->id,
            "更新表单设置：{$form->name}", $auditBefore, $form->only($auditAllowed), $auditAllowed);

        PageCache::flush();

        return back()->with('success', '表单设置已保存。');
    }

    public function destroy(Form $form)
    {
        if ($form->slug === Form::DEFAULT_SLUG) {
            return back()->with('error', '默认联系表单不可删除（可停用或清空字段）。');
        }

        AuditLog::record('form.destroy', "删除表单：{$form->name}", [], 'Form', $form->id);
        $form->fields()->delete();
        $form->delete();
        PageCache::flush();

        return redirect()->route('admin.forms.index')->with('success', '表单已删除。');
    }

    public function toggle(Form $form)
    {
        $willEnable = ! $form->isEnabled();
        $form->update([
            'status' => $willEnable ? Form::STATUS_ENABLED : Form::STATUS_DISABLED,
        ]);
        AuditLog::record('form.toggle', ($willEnable ? '启用表单：' : '停用表单：').$form->name,
            [], 'Form', $form->id);
        PageCache::flush();

        return back();
    }

    // ---------------- Fields ----------------

    public function createField(Form $form)
    {
        $field = new FormField([
            'type'       => 'text',
            'required'   => false,
            'sort_order' => (int) $form->fields()->max('sort_order') + 1,
        ]);
        $rows = collect();
        $locales = LocaleRegistry::supported();
        $fieldTypes = FieldTypeRegistry::all();

        return view('admin.forms.field', compact('form', 'field', 'rows', 'locales', 'fieldTypes'));
    }

    public function storeField(Request $request, Form $form)
    {
        [$struct, $translations] = $this->validateField($request);

        $sort = (int) $form->fields()->max('sort_order') + 1;

        foreach (LocaleRegistry::supported() as $loc) {
            $t = $translations[$loc] ?? [];

            FormField::create([
                'form_id'     => $form->id,
                'site_id'     => $form->site_id,
                'type'        => $struct['type'],
                'name'        => $struct['name'],
                'required'    => (bool) ($struct['required'] ?? false),
                'validation'  => $struct['validation'] ?? null,
                'sort_order'  => $sort,
                'locale'      => $loc,
                'label'       => $t['label'] ?? null,
                'placeholder' => $t['placeholder'] ?? null,
                'help_text'   => $t['help_text'] ?? null,
                'options'     => $this->normalizeOptions($struct['type'], $t['options'] ?? ''),
            ]);
        }

        AuditLog::record('form_field.store', "添加字段：{$struct['name']}（{$struct['type']}）",
            [], 'Form', $form->id);

        PageCache::flush();

        return redirect()->route('admin.forms.edit', $form)->with('success', '字段已添加。');
    }

    public function editField(Form $form, FormField $field)
    {
        $rows = $form->fields()->where('name', $field->name)->get()->keyBy('locale');
        $locales = LocaleRegistry::supported();
        $fieldTypes = FieldTypeRegistry::all();

        return view('admin.forms.field', compact('form', 'field', 'rows', 'locales', 'fieldTypes'));
    }

    public function updateField(Request $request, Form $form, FormField $field)
    {
        [$struct, $translations] = $this->validateField($request);

        foreach (LocaleRegistry::supported() as $loc) {
            $row = $form->fields()->where('name', $field->name)->where('locale', $loc)->first();
            if (! $row) {
                continue;
            }
            $t = $translations[$loc] ?? [];

            $row->update([
                'type'        => $struct['type'],
                'name'        => $struct['name'],
                'required'    => (bool) ($struct['required'] ?? false),
                'validation'  => $struct['validation'] ?? null,
                'label'       => $t['label'] ?? $row->label,
                'placeholder' => $t['placeholder'] ?? $row->placeholder,
                'help_text'   => $t['help_text'] ?? $row->help_text,
                'options'     => $this->normalizeOptions(
                    $struct['type'],
                    $t['options'] ?? null,
                    $row
                ),
            ]);
        }

        AuditLog::record('form_field.update', "更新字段：{$field->name}", [], 'Form', $form->id);

        PageCache::flush();

        return redirect()->route('admin.forms.edit', $form)->with('success', '字段已保存。');
    }

    public function destroyField(Form $form, FormField $field)
    {
        AuditLog::record('form_field.destroy', "删除字段：{$field->name}", [], 'Form', $form->id);
        $form->fields()->where('name', $field->name)->delete();
        PageCache::flush();

        return back()->with('success', '字段已删除。');
    }

    // ---------------- Submissions（只读） ----------------

    public function submissions()
    {
        $submissions = FormSubmission::with('form')->orderByDesc('id')->paginate(30);

        return view('admin.forms.submissions', ['submissions' => $submissions, 'scopeForm' => null]);
    }

    public function formSubmissions(Form $form)
    {
        $submissions = $form->submissions()->with('form')->orderByDesc('id')->paginate(30);

        return view('admin.forms.submissions', ['submissions' => $submissions, 'scopeForm' => $form]);
    }

    // ---------------- Validation ----------------

    private function validateForm(Request $request): array
    {
        $siteId = SiteContext::currentSiteId();

        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'slug'        => [
                'required', 'string', 'max:120', 'alpha_dash',
                Rule::unique('forms', 'slug')
                    ->ignore($request->route('form')?->id)
                    ->where('site_id', $siteId),
            ],
            'title'       => 'nullable|string|max:160',
            'success_message' => 'nullable|string|max:400',
            'status'      => ['required', Rule::in([Form::STATUS_ENABLED, Form::STATUS_DISABLED])],
            'consent_required'      => 'nullable|boolean',
            'honeypot_enabled'      => 'nullable|boolean',
            'notification_enabled'  => 'nullable|boolean',
            'notification_channels' => 'required|string|max:60',
            'notification_recipients' => 'nullable|string|max:255',
        ]);

        foreach (['consent_required', 'honeypot_enabled', 'notification_enabled'] as $bool) {
            $data[$bool] = $request->boolean($bool);
        }

        $data['site_id'] = $siteId;

        return $data;
    }

    /**
     * @return array{0:array,1:array}
     */
    private function validateField(Request $request): array
    {
        $struct = $request->validate([
            'type'       => ['required', Rule::in(array_keys(FieldTypeRegistry::all()))],
            'name'       => ['required', 'string', 'max:80', 'alpha_dash'],
            'required'   => 'nullable|boolean',
            'validation' => 'nullable|string|max:160',
        ]);

        $request->validate([
            'translations'                   => 'array',
            'translations.*.label'           => 'nullable|string|max:160',
            'translations.*.placeholder'     => 'nullable|string|max:160',
            'translations.*.help_text'        => 'nullable|string|max:255',
            'translations.*.options'         => 'nullable|string',
        ]);

        $translations = (array) $request->input('translations', []);

        return [$struct, $translations];
    }

    /**
     * 仅需要选项的字段类型保存 options（逐行）；其余类型清空。raw=null 且存在
     * 旧值时保留旧值（用于未提交该 locale 的场景）。
     */
    private function normalizeOptions(string $type, ?string $raw, ?FormField $existing = null): ?string
    {
        if (! FieldTypeRegistry::needsOptions($type)) {
            return null;
        }
        if ($raw === null) {
            return $existing?->options;
        }

        $lines = array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: [])
        );

        return $lines ? implode("\n", $lines) : null;
    }
}
