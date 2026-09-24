<?php

namespace App\Support\Forms;

use App\Models\FormSubmission;
use App\Models\Inquiry;

/**
 * Inquiry 投影器（P-STEP 18H-2，D1）。
 * --------------------------------------------------
 * 把完整事实 FormSubmission（payload JSON）确定性投影为后台跟进用的 Inquiry
 * （固定列、向后兼容）。字段有限也不丢数据：未映射的字段保留在 payload。
 *
 *  - 标准列 name/phone/email/company/message 按常见字段名取值；
 *  - message 为空时，用其余文本字段确定性拼接，保证后台可读；
 *  - demand_type/monthly_use 无值时回落默认 / null（不再写死制造业选项）。
 */
class InquiryProjector
{
    /** payload 中可能承载电话 / 邮箱 / 留言的字段名（按优先级）。 */
    private const PHONE_KEYS = ['phone', 'tel', 'mobile', 'telephone'];
    private const EMAIL_KEYS = ['email', 'mail'];
    private const MESSAGE_KEYS = ['message', 'note', 'content', 'body', 'remark', 'comments'];

    public function project(FormSubmission $submission): Inquiry
    {
        $payload = $submission->payloadArray();

        $name = $this->firstString($payload, ['name', 'full_name', 'contact_name', 'username']);
        $phone = $this->firstString($payload, self::PHONE_KEYS);
        $email = $this->firstString($payload, self::EMAIL_KEYS);
        $company = $this->firstString($payload, ['company', 'organization', 'org', 'company_name']);
        $message = $this->firstString($payload, self::MESSAGE_KEYS);

        $demandType = $this->firstString($payload, [
            'demand_type', 'inquiry_type', 'subject', 'type', 'request_type',
        ]);
        $monthlyUse = $this->firstString($payload, ['monthly_use', 'quantity', 'volume']);

        if (trim($message) === '') {
            $message = $this->summarize($payload, $name, $phone, $email, $company, $demandType);
        }

        return new Inquiry([
            'submission_id' => $submission->id,
            'form_id'       => $submission->form_id,
            'site_id'       => $submission->site_id,
            'name'          => mb_substr($name !== '' ? $name : '（未留称呼）', 0, 50),
            'phone'         => mb_substr($phone, 0, 30),
            'email'         => mb_substr($email, 0, 120),
            'company'       => mb_substr($company, 0, 120) ?: null,
            'demand_type'   => mb_substr($demandType !== '' ? $demandType : '其他', 0, 20),
            'monthly_use'   => mb_substr($monthlyUse, 0, 60) ?: null,
            'message'       => mb_substr($message, 0, 1000),
            'source_page'   => mb_substr((string) $submission->source_page, 0, 255),
            'landing_url'   => mb_substr((string) $submission->landing_url, 0, 255),
            'referer'       => mb_substr((string) $submission->referer, 0, 255),
            'utm_source'    => $submission->utm_source,
            'utm_medium'    => $submission->utm_medium,
            'utm_campaign'  => $submission->utm_campaign,
            'utm_term'      => $submission->utm_term,
            'utm_content'   => $submission->utm_content,
            'device_type'   => $submission->device_type,
            'ip'            => $submission->ip,
            'user_agent'    => $submission->user_agent,
            'status'        => 'new',
        ]);
    }

    /** 按候选键取第一个非空标量（字符串）；数组 / 多选转逗号拼接。 */
    private function firstString(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if (is_array($value)) {
                $value = implode(', ', array_filter(array_map(static fn ($v) => trim((string) $v), $value)));
            }
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** message 缺失时，把其余未映射的文本 / 选项字段确定性拼接为可读摘要。 */
    private function summarize(
        array $payload,
        string $name,
        string $phone,
        string $email,
        string $company,
        string $demandType
    ): string {
        $used = array_merge(
            ['name', 'full_name', 'contact_name', 'username'],
            self::PHONE_KEYS, self::EMAIL_KEYS,
            ['company', 'organization', 'org', 'company_name'],
            ['demand_type', 'inquiry_type', 'subject', 'type', 'request_type'],
            ['monthly_use', 'quantity', 'volume'],
            self::MESSAGE_KEYS,
            // 归因 / 系统字段不进摘要
            ['website', 'landing_url', 'referer', 'utm_source', 'utm_medium',
                'utm_campaign', 'utm_term', 'utm_content', 'consent'],
        );

        $lines = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, $used, true) || $value === null || $value === '') {
                continue;
            }
            if (is_array($value)) {
                $value = implode(', ', array_filter(array_map(static fn ($v) => trim((string) $v), $value)));
            }
            $value = trim((string) $value);
            if ($value !== '') {
                $lines[] = $key . ': ' . mb_substr($value, 0, 200);
            }
        }

        if ($lines === []) {
            return $demandType !== '' ? $demandType : '在线提交（未填写留言）';
        }

        return implode('；', $lines);
    }
}
