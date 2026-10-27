<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 路由隐式绑定的整型主键守卫（跨库一致性）。
 *
 * 背景：当模型主键是整型而 URL 片段不是合法整数时，
 * SQLite / MySQL 查不到记录返回 404，PostgreSQL 会抛 22P02 变成 500。
 * 守卫在进入数据库之前拦截，使三个数据库的响应口径一致。
 *
 * 本测试不依赖具体路由，直接验证绑定解析层的判定，
 * 从而同时覆盖全部被隐式绑定的模型（它们共用 BaseModel）。
 */
class RouteBindingGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 合法整数片段必须仍然绑定到真实记录（阳性对照）。
     */
    #[DataProvider('validIntegerProvider')]
    public function test_valid_integer_segment_still_binds(string $template): void
    {
        $content = $this->seedContent();

        $resolved = (new Content())->resolveRouteBinding(
            str_replace('{id}', (string) $content->id, $template)
        );

        $this->assertNotNull($resolved, '合法整数片段必须能绑定到记录');
        $this->assertSame($content->id, $resolved->id);
    }

    public static function validIntegerProvider(): array
    {
        return [
            'numeric string'       => ['{id}'],
            'leading zeros'        => ['0{id}'],
            'plus sign'            => ['+{id}'],
            'leading whitespace'   => [' {id}'],
            'trailing whitespace'  => ['{id} '],
            'both-side whitespace' => [' {id} '],
        ];
    }

    /**
     * 整数原值（非字符串）同样必须能绑定。
     */
    public function test_integer_value_still_binds(): void
    {
        $content = $this->seedContent();

        $resolved = (new Content())->resolveRouteBinding((int) $content->id);

        $this->assertNotNull($resolved);
        $this->assertSame($content->id, $resolved->id);
    }

    /**
     * 非法片段必须被判定为「未找到」，且不得触发数据库类型转换错误。
     */
    #[DataProvider('invalidSegmentProvider')]
    public function test_invalid_segment_is_rejected_without_database_error(string $segment): void
    {
        $this->seedContent();

        $resolved = (new Content())->resolveRouteBinding($segment);

        $this->assertNull($resolved, '非法片段必须按未找到处理');
    }

    public static function invalidSegmentProvider(): array
    {
        return [
            'non-numeric word'       => ['check'],
            'mixed alnum'            => ['12abc'],
            'decimal'                => ['1.5'],
            'negative'               => ['-1'],
            'scientific notation'    => ['1e3'],
            'empty string'           => [''],
            'whitespace only'        => ['   '],
            'overflow beyond bigint' => ['99999999999999999999'],
            'negative overflow'      => ['-99999999999999999999'],
            'sql-ish payload'        => ['1 or 1=1'],
            'hex literal'            => ['0x10'],
            'trailing dot'           => ['10.'],
            'plus sign only'         => ['+'],
            'minus sign only'        => ['-'],
        ];
    }

    /**
     * 守卫不得影响非整型主键的模型。
     */
    public function test_string_key_model_is_not_guarded(): void
    {
        $model = new class extends \App\Models\BaseModel
        {
            protected $table = 'settings';

            protected $keyType = 'string';

            public $incrementing = false;
        };

        $this->assertSame('string', $model->getKeyType());

        $method = new \ReflectionMethod($model, 'rejectsRouteBindingValue');

        $this->assertFalse(
            $method->invoke($model, 'not-a-number', null),
            '非整型主键不得套用数字判定'
        );
    }

    /**
     * 端到端：真实路由收到非数字 ID 时必须返回 404，而不是数据库类型错误导致的 500。
     *
     * 该用例是本缺陷的原始现场（POST /admin/contents/check 带 _method=PUT，
     * 被路由到 PUT contents/{content}，'check' 被当作 bigint 送进 SQL）。
     */
    public function test_http_route_with_non_numeric_id_returns_404_not_500(): void
    {
        $this->seed();
        $admin = User::firstOrFail();

        $response = $this->actingAs($admin)
            ->postJson('/admin/contents/check', ['_method' => 'PUT']);

        $this->assertSame(
            404,
            $response->getStatusCode(),
            '非数字内容 ID 必须返回 404，不能因数据库类型转换差异变成 500'
        );
    }

    /**
     * 端到端：合法的数字 ID 仍能走到业务逻辑（不能被守卫误杀成 404）。
     */
    public function test_http_route_with_numeric_id_reaches_controller(): void
    {
        $this->seed();
        $admin = User::firstOrFail();
        $content = $this->seedContent();

        $response = $this->actingAs($admin)
            ->postJson("/admin/contents/{$content->id}/publish");

        // 走到控制器即证明绑定成功（未进入 404 分支）。
        $this->assertNotSame(
            404,
            $response->getStatusCode(),
            '合法数字 ID 必须能绑定到内容记录'
        );
    }

    private function seedContent(): Content
    {
        $site = Site::query()->first();
        $this->assertNotNull($site, '测试库需要至少一个站点');
        SiteContext::setSite($site);

        return Content::query()->create([
            'site_id' => $site->id,
            'type'    => 'article',
            'title'   => 'route binding guard probe',
            'slug'    => 'route-binding-guard-' . uniqid(),
            'locale'  => 'zh-CN',
            'status'  => 'draft',
        ]);
    }
}
