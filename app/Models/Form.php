<?php

namespace App\Models;

use App\Support\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 表单定义（P-STEP 18H-2）。
 * --------------------------------------------------
 * Form 是 site-scoped 的「数据结构 + 行为开关」：字段由 FormField（每 locale 行）
 * 承载；本模型只保存成功文案、consent、蜜罐、通知开关 / 收件人等行为配置。
 *
 * 通知收件人（D4，两层）：recipientList() 为第一优先级（Form 级）；为空时
 * 调用方回退 Site 级 setting，不允许 config / 硬编码邮箱。
 *
 * 视觉不由 Form 负责（Theme + Component + FormReference block）。
 */
class Form extends Model
{
    use BelongsToSite;

    protected $guarded = [];

    protected $casts = [
        'consent_required'      => 'boolean',
        'honeypot_enabled'      => 'boolean',
        'notification_enabled'  => 'boolean',
    ];

    public const STATUS_ENABLED  = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    /** 出厂默认联系表单 slug（DefaultFormSeeder 幂等创建）。 */
    public const DEFAULT_SLUG = 'contact';

    /** 全部字段（按 sort）。 */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('sort_order');
    }

    /** 指定语言的字段集合。 */
    public function fieldsForLocale(string $locale)
    {
        return $this->fields()->where('locale', $locale)->get();
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function isEnabled(): bool
    {
        return $this->status === self::STATUS_ENABLED;
    }

    /**
     * Form 级收件人（第一优先级）：逗号 / 分号 / 换行分隔，仅保留合法邮箱；
     * 未配置返回空数组（调用方再回退 Site 级）。
     *
     * @return array<int,string>
     */
    public function recipientList(): array
    {
        return self::splitRecipients((string) $this->notification_recipients);
    }

    /**
     * @return array<int,string>
     */
    public static function splitRecipients(string $raw): array
    {
        $parts = preg_split('/[,;,' . "\r\n" . ']+/', $raw) ?: [];

        return array_values(array_filter(
            array_map(static fn ($e) => trim((string) $e), $parts),
            static fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
        ));
    }
}
