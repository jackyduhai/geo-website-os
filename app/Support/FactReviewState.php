<?php

namespace App\Support;

use App\Models\Fact;
use App\Models\FactReviewMarker;

/**
 * WP-8 事实复核状态派生（机器可判定的复核状态权威读取）。
 * ------------------------------------------------------------------
 * 对公开 Fact 派生稳定的复核状态：HISTORICAL / VERIFIED / REVIEW-DUE。
 *
 * 规则（冻结）：
 *  ① temporality 由「稳定 fact key contract」决定——本类显式登记的
 *     HISTORICAL_KEYS（一次性、已发生、真值不因当前实体状态改变）。
 *     严禁用 review_due == NULL 反推 HISTORICAL（运行时启发式）。
 *  ② 当前态事实：scope 存在 open marker（WP-8 复核信号）或 review_due
 *     已到期 → REVIEW-DUE；否则 VERIFIED。
 *  ③ marker 是当前复核状态权威；AuditLog 仅事件证据，不参与状态判定。
 */
class FactReviewState
{
    public const HISTORICAL = 'HISTORICAL';
    public const VERIFIED = 'VERIFIED';
    public const REVIEW_DUE = 'REVIEW-DUE';

    /**
     * 稳定 fact temporality 契约：显式登记的历史事件 fact key。
     * 这些 key 描述已发生的一次性事件，真值不随后续实体状态 / 复核改变。
     * 新增历史事实时必须在此登记，而不是靠字段启发式推断。
     */
    public const HISTORICAL_KEYS = [
        'FACT-COMPANY-003', // 成立时间
        'FACT-COMPANY-004', // 正式投产
    ];

    /**
     * 该 scope 是否存在未决（open）复核 marker。
     */
    public function hasOpenMarker(
        int $siteId,
        string $scope = FactReviewMarker::SCOPE_SITE,
        ?string $scopeKey = null
    ): bool {
        return FactReviewMarker::withoutSiteScope()
            ->where('site_id', $siteId)
            ->where('status', FactReviewMarker::STATUS_OPEN)
            ->where('scope', $scope)
            ->when($scopeKey !== null, fn ($q) => $q->where('scope_key', $scopeKey))
            ->exists();
    }

    /**
     * 站点级当前是否需复核（任意 open marker）。
     */
    public function siteNeedsReview(int $siteId): bool
    {
        return FactReviewMarker::withoutSiteScope()
            ->where('site_id', $siteId)
            ->where('status', FactReviewMarker::STATUS_OPEN)
            ->exists();
    }

    /**
     * 单个 Fact 的复核状态（依据稳定 key contract + review 字段 + marker）。
     */
    public function forFact(Fact $fact): string
    {
        // ① 历史事实：由稳定 key contract 判定，不看 review_due。
        if (in_array($fact->key, self::HISTORICAL_KEYS, true)) {
            return self::HISTORICAL;
        }

        // ② 当前态事实：scope open marker 或 review_due 到期 → REVIEW-DUE。
        if ($this->siteNeedsReview((int) $fact->site_id)) {
            return self::REVIEW_DUE;
        }

        if ($fact->review_due !== null && $fact->review_due->isPast()) {
            return self::REVIEW_DUE;
        }

        return self::VERIFIED;
    }
}
