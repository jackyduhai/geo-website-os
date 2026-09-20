<?php

namespace Tests\Unit;

use Illuminate\Routing\UrlGenerator as BaseUrlGenerator;
use Tests\TestCase;

/**
 * P-STEP 07 / P0-A：URL Generator 行为对拍（Golden Tests）。
 *
 * 行为盘点结论：旧站点专属 UrlGenerator 仅有一个通用行为——
 * 「调用方传入以 / 结尾的目录型路径时，保住尾斜杠」（框架 UrlGenerator::format
 * 会剥掉尾斜杠）；详情型路径、文件路径、query、fragment 完全不动。
 * 该行为与业务无关，属通用 URL 契约。
 *
 * 本文件锁定金标准输出；替换实现（GeoUrlGenerator）后逐项对比，
 * 证明「旧 URL == 新 URL」。
 */
class UrlGeneratorGoldenTest extends TestCase
{
    private BaseUrlGenerator $gen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gen = app('url');
    }

    public static function goldenUrls(): array
    {
        // [输入路径, 期望输出 path+query+fragment 部分（主机部分由框架补全）]
        return [
            // 实测捕获的现行为：root 输出主机根（框架会剥掉 '/ 尾斜杠'），见专用用例
            'directory keeps slash'       => ['/products/', '/products/'],
            'detail no slash added'       => ['/products/epoxy-primer-100', '/products/epoxy-primer-100'],
            'file path untouched'         => ['/sitemap.xml', '/sitemap.xml'],
            'nested directory keeps slash' => ['/knowledge/selection/', '/knowledge/selection/'],
            'query preserved'             => ['/search?q=abc', '/search?q=abc'],
            'fragment preserved'          => ['/about/#team', '/about/#team'],
            'slash + query + fragment'    => ['/news/?page=2#list', '/news/?page=2#list'],
            // 实测捕获：末段含点（文件样）不补斜杠
            'inner dir with file-like last seg' => ['/downloads/v1.0/', '/downloads/v1.0'],
        ];
    }

    /**
     * @dataProvider goldenUrls
     */
    public function test_url_generator_golden_output(string $path, string $expectedPath): void
    {
        $url = $this->gen->to($path);

        // 主机/协议部分由框架决定；断言 path+query+fragment 部分与金标准一致
        $this->assertStringEndsWith($expectedPath, $url, "输入 {$path} 的输出不符合金标准");
        $this->assertStringStartsWith('http://', $url);
    }

    public function test_root_url_returns_host_without_forced_slash(): void
    {
        // 实测捕获的现行为：root 输出主机根，不强制补尾斜杠
        $url = $this->gen->to('/');

        $this->assertStringStartsWith('http://', $url);
        $this->assertFalse(str_ends_with($url, '//'));
    }

    public function test_trailing_slash_with_query_and_fragment_composition(): void
    {
        // 组合场景：目录型路径 + query + fragment，斜杠只补在 path 尾部
        $url = $this->gen->to('/news/?page=2#top');

        $this->assertStringEndsWith('/news/?page=2#top', $url);
        $this->assertStringNotContainsString('/news//', $url);
    }

    public function test_detail_path_with_query_gets_no_slash(): void
    {
        $url = $this->gen->to('/products/epoxy-primer-100?ref=x');

        $this->assertStringEndsWith('/products/epoxy-primer-100?ref=x', $url);
    }
}
