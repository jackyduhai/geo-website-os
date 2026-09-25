<?php

namespace Tests\Feature;

use App\Models\FormSubmission;
use App\Support\PageCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18L-2b：Component System Completion（spacing 标准化 + 位移 token + Loading/异步表单）。
 *
 * 防止「内联 spacing / hover 位移 / transition 时长回潮」与「表单无就地反馈」：
 *   - spacing 标准化为 sp-N = N×4px（sp-1..24，覆盖 4–96），blade 内联 gap/padding/margin
 *     一律消费 var(--sp-*)；sr-only 的 margin:-1px 属刻意隐藏，豁免。
 *   - 位移语义化：--lift-press / --lift-card / --nudge / --rise，取代散落 translateY/translateX。
 *   - TD-36：表单异步提交（fetch + Accept JSON）→ aria-busy + .is-loading spinner +
 *     就地成功 / 422 字段错误 / role=alert 通用错误；无 fetch 时整页提交兜底。
 */
class ComponentSystemCompletion18L2bTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        PageCache::flush();
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'name'    => '王工',
            'phone'   => '13800001111',
            'email'   => 'wang@example.com',
            'message' => '想了解产品规格。',
            'website' => '',
        ], $override);
    }

    public function test_spacing_scale_standardized_to_four_point_steps(): void
    {
        $html = $this->get('http://localhost/')->assertOk()->getContent();

        foreach (['--sp-5: 20px', '--sp-7: 28px', '--sp-12: 48px', '--sp-24: 96px'] as $decl) {
            $this->assertStringContainsString($decl, $html, "缺 spacing 声明 {$decl}");
        }
    }

    public function test_lift_and_nudge_tokens_are_declared(): void
    {
        $html = $this->get('http://localhost/')->assertOk()->getContent();

        foreach (['--lift-press: -1px', '--lift-card: -2px', '--nudge: 3px', '--rise: 10px'] as $decl) {
            $this->assertStringContainsString($decl, $html, "缺位移声明 {$decl}");
        }
    }

    public function test_loading_css_spinner_and_inline_error_are_present(): void
    {
        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringContainsString('.btn.is-loading', $html);
        $this->assertStringContainsString('@keyframes btnspin', $html);
        $this->assertStringContainsString('.lead-form-err', $html);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $html);
    }

    public function test_async_submit_returns_success_json(): void
    {
        $this->post(
            'http://localhost/forms/contact/submit',
            $this->payload(),
            ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']
        )->assertOk()->assertJson(['success' => true]);

        $submission = FormSubmission::firstOrFail();
        $this->assertSame('zh-CN', $submission->locale);
        $this->assertSame('王工', $submission->payloadArray()['name']);
    }

    public function test_async_validation_failure_returns_422_errors(): void
    {
        $this->post(
            'http://localhost/forms/contact/submit',
            $this->payload(['name' => '', 'phone' => '']),
            ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']
        )->assertStatus(422)->assertJsonStructure(['errors' => ['name', 'phone']]);

        $this->assertSame(0, FormSubmission::count());
    }

    public function test_traditional_submit_redirects_with_flash(): void
    {
        $this->post('http://localhost/forms/contact/submit', $this->payload())
            ->assertRedirect()
            ->assertSessionHas('lead_success', '已收到，我们会尽快联系你。');

        $this->assertSame(1, FormSubmission::count());
    }

    public function test_dynamic_form_source_wires_async_feedback(): void
    {
        $src = file_get_contents(resource_path('views/site/dynamic_form.blade.php'));

        $this->assertStringContainsString('fetch(form.action', $src);
        $this->assertStringContainsString("setAttribute('aria-busy'", $src);
        $this->assertStringContainsString("classList.add('is-loading')", $src);
        // 无 fetch 时保留原生整页提交（渐进增强）。
        $this->assertStringContainsString('if(!window.fetch', $src);
    }

    public function test_no_blade_under_site_uses_inline_spacing_px(): void
    {
        $dir = resource_path('views/site');
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        $offenders = [];
        foreach ($it as $file) {
            if (substr($file->getFilename(), -10) !== '.blade.php') {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            // gap/padding/margin 后仍写 px；sr-only 的 margin:-1px 经负向先行豁免。
            if (preg_match('/(?:gap|padding|margin[a-z-]*):\s*(?![^;"}]*-1px)[^;"}]*[0-9]+px/', $src)) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, '仍有 blade 使用内联 spacing px：' . implode('; ', $offenders));
    }
}
