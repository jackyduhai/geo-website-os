<?php

namespace Tests;

use App\Support\RequestScopedState;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        // 跨用例 / 跨测试类隔离：RefreshDatabase 每个用例重建内存库（site id 重置），
        // 但 SiteContext 与各类请求级 static memo 不会自动归零。若上个用例把上下文停在
        // 一个在新库中不存在的 site，currentSiteId() 仍返回旧 id → FK / 串站 / 脏读。
        // 每个用例结束统一复位全部请求级状态与站点上下文。
        RequestScopedState::flushAll();
        SiteContext::clear();

        parent::tearDown();
    }
}
