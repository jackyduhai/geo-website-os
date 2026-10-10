<?php

namespace Tests\Feature;

use App\Models\Fact;
use App\Models\FactReviewMarker;
use App\Models\Entity;
use App\Models\Site;
use App\Models\User;
use App\Support\Catalog;
use App\Support\FactReviewSignaler;
use App\Support\FactReviewState;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WP-8 Purge → Fact Re-review：事实复核信号与状态权威。
 *
 * 覆盖：
 *  - Entity purge 后产生 site 粒度 open marker（含 triggered_by_entity_id / reason）；
 *  - 同时产生一次性 AuditLog 事件（fact.review_signaled）；
 *  - Fact 行完全不被 mutate（row / value / locale / is_public 前后一致）；
 *  - marker 是当前复核状态权威：FactReviewState 从 marker 读取，resolved 后翻转；
 *  - 复核状态按稳定 key contract 派生：HISTORICAL / VERIFIED / REVIEW-DUE，
 *    禁止 review_due==NULL→HISTORICAL；
 *  - 幂等（重复信号不产生重复 open marker）；
 *  - 跨站隔离（marker 不跨站）。
 */
class FactReReviewSignalTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Site $site;
    protected FactReviewState $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->site = Site::create([
            'name' => 'Review Site', 'slug' => 'review-site',
            'domain' => 'review.test', 'status' => 'active', 'is_default' => false,
        ]);
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($this->admin)
            ->post(route('admin.sites.switch'), ['site_id' => $this->site->id])
            ->assertRedirect();
        SiteContext::setSite($this->site);
        Catalog::flush();
        $this->state = new FactReviewState();
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function makeEntity(string $type, string $slug, array $overrides = []): Entity
    {
        return Entity::create(array_merge([
            'site_id' => $this->site->id,
            'type' => $type,
            'slug' => $slug,
            'name' => 'Entity '.$slug,
            'status' => Entity::STATUS_PUBLISHED,
            'locale' => 'zh-CN',
            'metadata' => ['core' => true],
        ], $overrides));
    }

    /** 经后台路由完成 软删 → 永久删除（purge）。 */
    private function purge(Entity $e): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.entities.destroy', $e))->assertRedirect();
        $this->actingAs($this->admin)
            ->delete(route('admin.entities.force-delete', $e->id))->assertRedirect();
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function makeFact(string $key, string $value, array $overrides = []): Fact
    {
        return Fact::create(array_merge([
            'site_id' => $this->site->id,
            'key' => $key,
            'locale' => 'zh-CN',
            'label' => $key,
            'value' => $value,
            'group' => 'company',
            'is_public' => true,
            'sort' => 10,
            'reviewed_at' => now()->subYear(),
            'review_due' => null,
        ], $overrides));
    }

    public function test_purge_creates_open_marker_at_site_scope(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'p-review');

        $this->purge($product);

        $this->assertDatabaseHas('fact_review_markers', [
            'site_id' => $this->site->id,
            'scope' => FactReviewMarker::SCOPE_SITE,
            'scope_key' => null,
            'triggered_by_entity_id' => $product->id,
            'status' => FactReviewMarker::STATUS_OPEN,
        ]);
    }

    public function test_purge_emits_audit_event(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'p-audit');

        $this->purge($product);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fact.review_signaled',
            'target_type' => 'entity',
            'target_id' => $product->id,
        ]);
    }

    public function test_purge_does_not_mutate_fact_rows(): void
    {
        $this->makeFact('FACT-COMPANY-011', '核心产品线 = A / B / C');
        $this->makeFact('FACT-BIZ-002', '核心客户群 = X / Y / Z', ['group' => 'business']);

        SiteContext::setSite($this->site);
        $before = Fact::withoutSiteScope()
            ->where('site_id', $this->site->id)
            ->get()
            ->map(fn (Fact $f) => [
                'key' => $f->key, 'value' => $f->value, 'locale' => $f->locale,
                'is_public' => (int) $f->is_public, 'group' => $f->group,
            ])
            ->values()
            ->toArray();

        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'p-facts');
        $this->purge($product);

        SiteContext::setSite($this->site);
        $after = Fact::withoutSiteScope()
            ->where('site_id', $this->site->id)
            ->get()
            ->map(fn (Fact $f) => [
                'key' => $f->key, 'value' => $f->value, 'locale' => $f->locale,
                'is_public' => (int) $f->is_public, 'group' => $f->group,
            ])
            ->values()
            ->toArray();

        $this->assertSame($before, $after, 'purge 前后 Fact 行（key/value/locale/is_public/group）必须完全一致');
    }

    public function test_marker_is_current_state_authority(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'p-authority');

        $this->assertFalse($this->state->siteNeedsReview($this->site->id), 'purge 前站点无 open marker');

        $this->purge($product);

        SiteContext::setSite($this->site);
        $this->assertTrue($this->state->siteNeedsReview($this->site->id), 'purge 后 marker 表明站点需复核');
        $this->assertTrue($this->state->hasOpenMarker($this->site->id));

        // 人工复核完成 → marker 置 resolved，状态翻转
        $marker = FactReviewMarker::withoutSiteScope()
            ->where('site_id', $this->site->id)
            ->where('status', FactReviewMarker::STATUS_OPEN)
            ->firstOrFail();
        $marker->resolve();

        $this->assertFalse($this->state->siteNeedsReview($this->site->id), 'resolved 后站点不再需复核');
        $this->assertNotNull($marker->fresh()->resolved_at);
    }

    public function test_review_state_derivation_uses_stable_key_contract(): void
    {
        // 历史事件 fact（在稳定 key contract 中登记）
        $historical = $this->makeFact('FACT-COMPANY-003', '2014 年 6 月');
        // 当前态 fact
        $current = $this->makeFact('FACT-COMPANY-011', '核心产品线 = A / B / C');
        // 当前态 fact：review_due 已到期
        $overdue = $this->makeFact('FACT-COMPANY-007', '年产能 1200 吨', ['review_due' => now()->subMonth()]);

        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'p-state');
        $this->purge($product);

        SiteContext::setSite($this->site);

        // 历史事实：恒为 HISTORICAL（即使站点有 open marker，真值不改变）
        $this->assertSame(FactReviewState::HISTORICAL, $this->state->forFact($historical->fresh()));

        // 当前态事实：站点有 open marker → REVIEW-DUE
        $this->assertSame(FactReviewState::REVIEW_DUE, $this->state->forFact($current->fresh()));

        // 复核到期事实 → REVIEW-DUE
        $this->assertSame(FactReviewState::REVIEW_DUE, $this->state->forFact($overdue->fresh()));

        // 解除 marker 后，当前态（无到期）→ VERIFIED；到期的仍 REVIEW-DUE
        FactReviewMarker::withoutSiteScope()
            ->where('site_id', $this->site->id)
            ->where('status', FactReviewMarker::STATUS_OPEN)
            ->get()
            ->each->resolve();

        SiteContext::setSite($this->site);
        $this->assertSame(FactReviewState::VERIFIED, $this->state->forFact($current->fresh()));
        $this->assertSame(FactReviewState::REVIEW_DUE, $this->state->forFact($overdue->fresh()));

        // 反启发式：review_due 为 NULL 的当前态事实（非历史 key）不得被判为 HISTORICAL
        $this->assertNotSame(FactReviewState::HISTORICAL, $this->state->forFact($current->fresh()));
    }

    public function test_marker_is_idempotent(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'p-idem');

        app(FactReviewSignaler::class)->signalForPurge($product);
        app(FactReviewSignaler::class)->signalForPurge($product);

        $this->assertSame(1, FactReviewMarker::withoutSiteScope()
            ->where('site_id', $this->site->id)
            ->where('triggered_by_entity_id', $product->id)
            ->count());
    }

    public function test_marker_is_site_isolated(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'p-iso');
        $this->purge($product);

        $siteB = Site::create([
            'name' => 'Review B', 'slug' => 'review-b',
            'domain' => 'review-b.test', 'status' => 'active', 'is_default' => false,
        ]);

        // Site B 不应看到 site A 的 open marker
        $this->assertFalse($this->state->siteNeedsReview($siteB->id));
        $this->assertTrue($this->state->siteNeedsReview($this->site->id));
    }
}
