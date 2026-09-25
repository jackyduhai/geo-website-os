<?php

namespace App\Support\Templates;

/**
 * 模板注册表（Template Registry）。
 * --------------------------------------------------
 * 双来源、单一注册表：
 *   - 核心模板：config/templates.php 声明式加载（base/home/landing/listing/detail/contact）
 *   - 包模板：由激活 Template Package 经 {@see registerPack()} 注册（如 mfg-*）
 *
 * 本类负责类型化访问、继承槽位合并；控制器 / Blade 不写模板选择分支。
 * 包模板为扩展来源：不能覆盖同名核心模板；核心与包合并后对外统一暴露。
 */
class TemplateRegistry
{
    /** @var array<string,TemplateDefinition> 核心模板 */
    private static array $coreDefs = [];

    /** @var array<string,TemplateDefinition> 激活包模板 */
    private static array $packDefs = [];

    private static bool $booted = false;

    /** 从配置加载核心模板（仅一次）。 */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        $raw = (array) config('templates.definitions', []);
        $defaultLayout = (string) config('templates.layout', 'layouts.site');

        foreach ($raw as $key => $cfg) {
            $slots = self::normalizeSlots((array) ($cfg['slots'] ?? []));

            // 合并父模板槽位（父需先声明；config 中 base 置于首位）。
            $parentKey = $cfg['extends'] ?? null;
            if ($parentKey !== null && isset(self::$coreDefs[$parentKey])) {
                // 子模板声明的槽位顺序为准；仅把父模板「独有」槽位追加其后。
                $slots = array_merge(
                    $slots,
                    array_diff_key(self::$coreDefs[$parentKey]->slots, $slots)
                );
            }

            self::$coreDefs[$key] = TemplateDefinition::fromConfig($key, $cfg, $defaultLayout, $slots);
        }

        self::$booted = true;
    }

    /** 全部重置（核心 + 包）。 */
    public static function flush(): void
    {
        self::$coreDefs = [];
        self::$packDefs = [];
        self::$booted = false;
    }

    /** 仅清除包模板（停用包 / 重新注册前调用）。 */
    public static function flushPacks(): void
    {
        self::$packDefs = [];
    }

    /**
     * 注册一个模板包提供的包级模板。
     *
     * @param  string  $packId      包 id（溯源用）
     * @param  array   $definitions template.json 中的 definitions（slots 可为简化格式）
     */
    public static function registerPack(string $packId, array $definitions): void
    {
        self::boot();
        $defaultLayout = (string) config('templates.layout', 'layouts.site');

        foreach ($definitions as $key => $cfg) {
            $cfg = is_array($cfg) ? $cfg : [];

            // 核心模板不可被包覆盖；同名包模板也不重复注册。
            if (isset(self::$coreDefs[$key]) || isset(self::$packDefs[$key])) {
                continue;
            }

            $slots = self::normalizeSlots((array) ($cfg['slots'] ?? []));

            // 父模板可来自核心或已注册包。
            $parentKey = $cfg['extends'] ?? null;
            if ($parentKey !== null) {
                $parent = self::get($parentKey);
                if ($parent !== null) {
                    $slots = array_merge($slots, array_diff_key($parent->slots, $slots));
                }
            }

            self::$packDefs[$key] = TemplateDefinition::fromConfig($key, $cfg, $defaultLayout, $slots);
        }
    }

    /** @return array<string,TemplateDefinition> 核心 + 激活包 */
    public static function all(): array
    {
        self::boot();

        return array_merge(self::$coreDefs, self::$packDefs);
    }

    public static function get(string $key): ?TemplateDefinition
    {
        self::boot();

        return self::$coreDefs[$key] ?? self::$packDefs[$key] ?? null;
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    /** 可在后台直接用于"新建页面"的模板（base 抽象类不直接使用）。 */
    public static function selectable(): array
    {
        return array_filter(
            self::all(),
            static fn (TemplateDefinition $d) => $d->key !== 'base'
        );
    }

    /**
     * 把包 template.json 的简化 slots 归一化为 ['label'=>, 'blocks'=>] 结构。
     * 支持：'*'、block type 列表 ["hero", ...]、完整 ['blocks'=> ...]。
     */
    private static function normalizeSlots(array $raw): array
    {
        $slots = [];

        foreach ($raw as $slotName => $slotCfg) {
            if ($slotCfg === '*' || $slotCfg === []) {
                $blocks = '*';
                $label = (string) $slotName;
            } elseif (is_array($slotCfg)) {
                if (array_key_exists('blocks', $slotCfg)) {
                    $blocks = $slotCfg['blocks'];
                } else {
                    $blocks = array_values(array_map('strval', $slotCfg));
                }
                $label = (string) ($slotCfg['label'] ?? $slotName);
            } else {
                $blocks = '*';
                $label = (string) $slotName;
            }

            $slots[(string) $slotName] = ['label' => $label, 'blocks' => $blocks];
        }

        return $slots;
    }
}
