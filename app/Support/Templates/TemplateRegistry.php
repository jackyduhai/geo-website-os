<?php

namespace App\Support\Templates;

/**
 * 模板注册表（Template Registry）。
 * --------------------------------------------------
 * 单一来源：模板从 config/templates.php 声明式加载；本类负责类型化访问与
 * 继承槽位合并。控制器 / Blade 不写模板选择分支。
 */
class TemplateRegistry
{
    /** @var array<string,TemplateDefinition> */
    private static array $defs = [];
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        $raw = (array) config('templates.definitions', []);
        $defaultLayout = (string) config('templates.layout', 'layouts.site');

        foreach ($raw as $key => $cfg) {
            $slots = (array) ($cfg['slots'] ?? []);
            // 合并父模板槽位（父需先声明；config 中 base 置于首位）
            $parentKey = $cfg['extends'] ?? null;
            if ($parentKey !== null && isset(self::$defs[$parentKey])) {
                // 子模板声明的槽位顺序为准；仅把父模板「独有」槽位追加其后。
                // 避免 array_merge(parent, child) 把父级 main 固定在首位，打乱
                // detail 的 header → main → related、listing 的 header → main → sidebar 顺序。
                $slots = array_merge(
                    $slots,
                    array_diff_key(self::$defs[$parentKey]->slots, $slots)
                );
            }
            self::$defs[$key] = TemplateDefinition::fromConfig($key, $cfg, $defaultLayout, $slots);
        }
        self::$booted = true;
    }

    public static function flush(): void
    {
        self::$defs = [];
        self::$booted = false;
    }

    /** @return array<string,TemplateDefinition> */
    public static function all(): array
    {
        self::boot();

        return self::$defs;
    }

    public static function get(string $key): ?TemplateDefinition
    {
        self::boot();

        return self::$defs[$key] ?? null;
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    /** 可在后台直接用于"新建页面"的模板（base 是抽象基类，不直接使用）。 */
    public static function selectable(): array
    {
        return array_filter(
            self::all(),
            static fn (TemplateDefinition $d) => $d->key !== 'base'
        );
    }
}
