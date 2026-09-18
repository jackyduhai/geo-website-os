<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Content;
use App\Models\Fact;
use Illuminate\Support\Facades\Schema;

/**
 * v0.6 术语规范化（合规/统一口径）：
 *  公开内容统一用「调理鸡肉」，不使用「鸡肉半成品 / 半成品 / 预制鸡肉」，
 *  与 SC 类别「调理鸡肉专区」及对外术语表保持一致。在 PHP 层替换，SQLite/PG 通用，幂等。
 */
return new class extends Migration
{
    public function up(): void
    {
        $map = ['鸡肉半成品' => '调理鸡肉', '半成品鸡肉' => '调理鸡肉', '预制鸡肉' => '调理鸡肉', '半成品' => '调理鸡肉'];
        $cols = ['title', 'summary', 'body', 'seo_title', 'seo_desc', 'geo_conclusion', 'geo_explanation'];

        foreach (Content::all() as $c) {
            $changed = false;
            foreach ($cols as $col) {
                if (! Schema::hasColumn($c->getTable(), $col)) {
                    continue;
                }
                $old = (string) $c->{$col};
                $new = strtr($old, $map);
                if ($new !== $old) {
                    $c->{$col} = $new;
                    $changed = true;
                }
            }
            if ($changed) {
                $c->save();
            }
        }

        foreach (Fact::all() as $f) {
            $new = strtr((string) $f->value, $map);
            if ($new !== (string) $f->value) {
                $f->value = $new;
                $f->save();
            }
        }
    }

    public function down(): void
    {
        // 术语统一不回退
    }
};
