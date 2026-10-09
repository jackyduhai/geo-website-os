<?php

namespace App\Support;

use App\Models\Menu;

/**
 * WP-6 菜单引用反查（删除前引用保护）。
 * ------------------------------------------------------------------
 * 唯一职责：删除 Page / Content 前，确认目标内部路径不再被任一 ACTIVE 菜单引用；
 * 仍被引用则由删除路径阻断（BLOCK），提示先解除 / 隐藏菜单。
 *
 * 红线：本 Guard 只做"引用保护"，绝不反向 mutate 菜单——不自动删除菜单项、
 * 不自动改写菜单目标（那等于未经授权修改引用方）。
 */
class MenuReferenceGuard
{
    /**
     * 把存储的链接归一化为内部路径（不含首尾斜杠）。
     * - 绝对 http(s)：取 path 部分；
     * - 相对路径：原样；
     * - tel / mailto / 锚点 / 空：返回空串（不参与内部路径匹配）。
     */
    public static function normalizePath(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $url === '#') {
            return '';
        }
        if (preg_match('~^https?://~i', $url)) {
            $path = (string) parse_url($url, PHP_URL_PATH);
        } elseif (preg_match('~^(tel:|mailto:)~i', $url)) {
            return '';
        } else {
            $path = $url;
        }
        // 去掉查询串 / 锚点
        $path = (string) preg_replace('~[#?].*$~', '', $path);

        return trim($path, '/');
    }

    /**
     * 目标内部路径是否仍被任一 ACTIVE 菜单引用。
     */
    public function isReferenced(string $targetPath): bool
    {
        $needle = self::normalizePath($targetPath);
        if ($needle === '') {
            return false;
        }

        return Menu::active()->get()
            ->contains(fn (Menu $m) => self::normalizePath($m->link()) === $needle);
    }
}
