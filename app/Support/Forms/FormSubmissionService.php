<?php

namespace App\Support\Forms;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Inquiry;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocaleRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 表单提交服务（P-STEP 18H-2，D1/D4）。
 * --------------------------------------------------
 * 统一承载所有表单（含旧 /inquiry 兼容入口）的提交：
 *
 *   蜜罐 → 按当前 locale 字段动态构建规则 → validate（服务端最终裁决）
 *        → DB 事务：FormSubmission（payload 全量）+ Inquiry 投影
 *        → 事务提交后通知（失败不丢数据）
 *
 * payload 仅保存字段定义内的业务字段；归因由 CaptureAttribution 的 session
 * 提供，设备 / IP / UA 以服务端为准。
 */
class FormSubmissionService
{
    public function __construct(
        private InquiryProjector $projector,
        private FormNotificationSender $notifier,
    ) {}

    /**
     * 记录一次提交。
     *
     * @return FormSubmission|null null 表示蜜罐命中（调用方静默成功，不落库）
     */
    public function record(Form $form, Request $request, ?string $locale = null): ?FormSubmission
    {
        $locale = $locale ?: (LocaleContext::current() ?: LocaleRegistry::default());

        // ---------- 蜜罐 ----------
        $honeypot = (string) config('forms.honeypot_field', 'website');
        if ($form->honeypot_enabled && filled($request->input($honeypot))) {
            return null;
        }

        // ---------- 字段（当前 locale；该语言无字段时回退默认语言） ----------
        $fields = $form->fieldsForLocale($locale);
        if ($fields->isEmpty() && $locale !== LocaleRegistry::default()) {
            $fields = $form->fieldsForLocale(LocaleRegistry::default());
        }

        [$rules, $attributes] = $this->buildRules($fields);

        $validated = $request->validate($rules, [], $attributes);

        // ---------- payload：仅字段定义内的业务字段 ----------
        $payload = [];
        foreach ($fields as $field) {
            $name = $field->name;
            if (array_key_exists($name, $validated)) {
                $payload[$name] = $validated[$name];
            } elseif ($field->type === 'checkbox' && ! $field->optionsArray()) {
                $payload[$name] = false; // 单个确认框未勾选
            }
        }

        $attr = $request->session()->isStarted() ? (array) $request->session()->get('attr', []) : [];

        // ---------- 事务：Submission（完整事实） + Inquiry（投影） ----------
        $submission = DB::transaction(function () use ($form, $payload, $attr, $request, $locale) {
            $submission = FormSubmission::create([
                'form_id'      => $form->id,
                'site_id'      => $form->site_id,
                'locale'       => $locale,
                'payload'      => $payload,
                'source_page'  => mb_substr((string) $request->headers->get('referer'), 0, 255),
                'landing_url'  => $this->clip($attr['landing_url'] ?? null, 255),
                'referer'      => $this->clip($attr['referer'] ?? null, 255),
                'utm_source'   => $this->clip($attr['utm_source'] ?? null, 64),
                'utm_medium'   => $this->clip($attr['utm_medium'] ?? null, 64),
                'utm_campaign' => $this->clip($attr['utm_campaign'] ?? null, 96),
                'utm_term'     => $this->clip($attr['utm_term'] ?? null, 64),
                'utm_content'  => $this->clip($attr['utm_content'] ?? null, 64),
                'device_type'  => Inquiry::deviceFromUserAgent($request->userAgent()),
                'ip'           => $request->ip(),
                'user_agent'   => mb_substr((string) $request->userAgent(), 0, 255),
                'status'       => 'new',
            ]);

            $inquiry = $this->projector->project($submission);
            $inquiry->save();

            return $submission;
        });

        // ---------- 事务提交后通知（失败不影响已保存数据） ----------
        $this->notifier->send($form, $submission);

        return $submission;
    }

    /**
     * 按字段（某 locale）动态构建校验规则与属性名（label）。
     *
     * @param  \Illuminate\Support\Collection<int,\App\Models\FormField>  $fields
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    private function buildRules($fields): array
    {
        $rules = [];
        $attributes = [];

        foreach ($fields as $field) {
            $name = $field->name;
            $options = $field->optionsArray();

            if ($field->type === 'checkbox') {
                if ($options) {
                    // 多勾选：array + 每项必须在选项内
                    $r = [$field->required ? 'required' : 'nullable', 'array'];
                    $rules[$name . '.*'] = [Rule::in($options)];
                } else {
                    // 单个确认框：required→accepted，否则允许缺省
                    $r = [$field->required ? 'accepted' : 'nullable'];
                }
            } else {
                $r = FieldTypeRegistry::baseRules($field->type);
                array_unshift($r, $field->required ? 'required' : 'nullable');

                if (in_array($field->type, ['select', 'radio'], true) && $options) {
                    $r[] = Rule::in($options);
                }
            }

            if (filled($field->validation)) {
                $r = array_merge($r, $this->parseExtraRules((string) $field->validation));
            }

            $rules[$name] = $r;
            $attributes[$name] = $field->label ?: $name;
        }

        return [$rules, $attributes];
    }

    /**
     * 解析字段附加规则（按 | 拆分；V1 不支持含 | 的 regex 附加规则）。
     *
     * @return array<int,string>
     */
    private function parseExtraRules(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', explode('|', $raw)),
            static fn ($r) => $r !== ''
        ));
    }

    private function clip(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
