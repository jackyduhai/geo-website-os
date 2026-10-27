<?php

namespace App\Models;

use App\Support\GuardsNumericRouteBinding;
use Illuminate\Database\Eloquent\Model;

/**
 * 业务模型基类。
 *
 * 统一承载跨库一致性守卫（GuardsNumericRouteBinding）：
 * 路由隐式绑定把 URL 片段原样送进查询，当主键是整型而片段不是数字时，
 * SQLite / MySQL 返回 404，PostgreSQL 会抛 22P02 变成 500。
 * 基类在进入数据库之前拦截，使三个数据库的响应口径一致。
 */
abstract class BaseModel extends Model
{
    use GuardsNumericRouteBinding;
}
