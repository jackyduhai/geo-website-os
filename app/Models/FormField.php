<?php

namespace App\Models;

use App\Support\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 表单字段（P-STEP 18H-2）。
 * --------------------------------------------------
 * 每 locale 一行（对齐 Content/Entity 翻译模型）：结构列（type/name/required/
 * validation）跨语言一致，label/placeholder/help_text/options 按 locale 翻译。
 * 逻辑字段以 (form_id,name) 标识，sort_order 跨语言相同。
 *
 * type 取值由 FieldTypeRegistry 裁决；options 供 select/radio/checkbox。
 */
class FormField extends Model
{
    use BelongsToSite;

    protected $guarded = [];

    protected $casts = [
        'required'   => 'boolean',
        'sort_order' => 'integer',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * select / radio / checkbox 选项：优先 JSON 数组，否则逐行；返回非空字符串数组。
     *
     * @return array<int,string>
     */
    public function optionsArray(): array
    {
        $raw = trim((string) $this->options);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter(
                array_map(static fn ($v) => trim((string) $v), $decoded),
                static fn ($v) => $v !== ''
            ));
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: [])
        ));
    }
}
