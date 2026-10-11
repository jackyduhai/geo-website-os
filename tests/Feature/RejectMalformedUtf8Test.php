<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * SEC-03 — RejectMalformedUtf8 守卫防回归。
 * ------------------------------------------------------------------
 * 中间件在 web / api 组最前面注册（bootstrap/app.php），把含非法 UTF-8 的输入
 * 拦成 422，否则数组字段（geo_evidence / geo_faq）转 JSON 存储时会
 * json_encode 抛 JsonEncodingException → 500。
 *
 * 本测试直接构造非法 UTF-8 字节，验证：
 *   - web 表单 POST 与 api JSON POST 都返回 422；
 *   - 中间件先于路由级鉴权（无 token / 未登录也先得到 422）；
 *   - 嵌套数组字段递归检测（含 JSON 原始内容）；
 *   - 合法 UTF-8 不被拦截。
 */
class RejectMalformedUtf8Test extends TestCase
{
    use RefreshDatabase;

    /** 非法 UTF-8：0xFF 是无效起始字节，0x28 在 0xC3 后不是合法续接字节。 */
    private function malformed(): string
    {
        return "ab\xFF\xC3\x28cd";
    }

    /** 直接发送原始 JSON（绕过测试客户端 json_encode，否则非法字节会在客户端报错）。 */
    private function rawJsonPost(string $uri, string $rawJson): TestResponse
    {
        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $rawJson);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('Admin@123456'),
            'is_super_admin' => true,
        ]);
    }

    public function test_web_form_post_with_malformed_utf8_is_rejected(): void
    {
        $this->admin();

        // 基线：合法登录成功（302 重定向到后台）。
        $ok = $this->post('/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'Admin@123456',
        ]);
        $ok->assertRedirect();

        // 登录字段本身合法，但附带一个含非法 UTF-8 的额外字段：控制器校验忽略额外
        // 字段（登录成功路径本应 302），因此这里的 422 只能来自中间件。
        $r = $this->post('/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'Admin@123456',
            'extra' => $this->malformed(),
        ]);
        $r->assertStatus(422);
    }

    public function test_api_json_post_with_malformed_utf8_is_rejected_before_token(): void
    {
        // 不带 token：中间件先于 geoflow.token 执行，应是 422 而非 401/403/503。
        $r = $this->rawJsonPost('/api/v1/geoflow/contents', '{"title":"'.$this->malformed().'"}');

        $r->assertStatus(422);
        $this->assertStringContainsString('invalid UTF-8', $r->getContent());
    }

    public function test_nested_array_field_with_malformed_utf8_is_rejected(): void
    {
        // 动机场景：数组字段 cast 为 JSON 存储，内部嵌套非法字节。
        $raw = '{"geo_evidence":[{"quote":"'.$this->malformed().'","url":"https://x.test"}]}';
        $r = $this->rawJsonPost('/api/v1/geoflow/contents', $raw);

        $r->assertStatus(422);
        $this->assertStringContainsString('invalid UTF-8', $r->getContent());
    }

    public function test_valid_utf8_passes_through_middleware(): void
    {
        // 合法 UTF-8（中英混合）不应被中间件拦截；无 token 时由 geoflow.token 处理，
        // 但绝不能出现中间件的 422 / 'invalid UTF-8'。
        $r = $this->rawJsonPost('/api/v1/geoflow/contents', '{"title":"正常标题 TITLE-1"}');

        $this->assertNotSame(422, $r->getStatusCode());
        $this->assertStringNotContainsString('invalid UTF-8', $r->getContent());
    }

    public function test_malformed_bytes_are_actually_invalid(): void
    {
        // 哨兵：确认测试构造的字节确实非法，避免“假变异”。
        $this->assertFalse(mb_check_encoding($this->malformed(), 'UTF-8'));
        $this->assertTrue(mb_check_encoding('正常标题 TITLE-1', 'UTF-8'));
    }
}
