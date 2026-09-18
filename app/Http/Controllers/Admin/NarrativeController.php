<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Support\Narrative;
use App\Support\PageCache;
use Illuminate\Http\Request;

/**
 * 后台「页面文案」：结构化页面可运营叙事段落（hero 导语 / 企业简介正文）
 * ------------------------------------------------------------------
 * 只编辑叙事，不碰硬数据（配比、参数、资质、时间线、数字、FAQ、产品组合仍在
 * 事实库）。覆盖以 contents.slot 片段存储，保存即发布、清空即恢复默认。
 */
class NarrativeController extends Controller
{
    public function index()
    {
        $groups = Narrative::registry();

        return view('admin.narrative.index', [
            'groups' => $groups,
            'total' => array_sum(array_map(fn ($g) => count($g['items']), $groups)),
            'customized' => array_sum(array_map(
                fn ($g) => count(array_filter($g['items'], fn ($i) => $i['customized'])),
                $groups
            )),
        ]);
    }

    public function edit(string $key)
    {
        $def = Narrative::definition($key);
        abort_if(! $def, 404);

        $row = Content::withoutGlobalScope('not_slot')
            ->where('slot', $key)->first();

        // 编辑框预填：已自定义用覆盖内容，否则预填默认文案（点保存才落库）
        $summary = old('summary', $row->summary ?? Narrative::defaultSummary($key));
        $body = old('body', $row->body ?? Narrative::defaultBody($key));

        return view('admin.narrative.edit', [
            'def' => $def,
            'key' => $key,
            'row' => $row,
            'summary' => $summary,
            'body' => $body,
            'defaultSummary' => Narrative::defaultSummary($key),
            'defaultBody' => Narrative::defaultBody($key),
        ]);
    }

    public function update(Request $request, string $key)
    {
        $def = Narrative::definition($key);
        abort_if(! $def, 404);

        $hasBody = ! empty($def['has_body']);

        // 导语与正文都为空：视为恢复默认（优先于必填校验，直接物理删除覆盖片段）
        $rawSummary = trim((string) $request->input('summary', ''));
        $rawBody = $hasBody ? trim((string) $request->input('body', '')) : '';
        if ($rawSummary === '' && $rawBody === '') {
            // 批量 forceDelete 不触发模型事件，需手动失效整页缓存与叙事内存
            Content::withoutGlobalScope('not_slot')->where('slot', $key)->forceDelete();
            Narrative::flush();
            PageCache::flush();

            return redirect()
                ->route('admin.narrative.index')
                ->with('success', '「' . $def['label'] . '」已清空自定义内容，恢复为系统默认文案。');
        }

        // 有内容时导语必填（hero 导语不允许为空，正文可空）
        $validated = $request->validate([
            'summary' => ['required', 'string', 'max:500'],
            'body'    => ['nullable', 'string', 'max:40000'],
        ]);

        $summary = trim((string) $validated['summary']);
        $body = $rawBody;

        // 清理可能存在的历史软删片段，避免 slot 唯一索引冲突
        Content::withoutGlobalScope('not_slot')->withTrashed()
            ->where('slot', $key)->whereNotNull('deleted_at')->forceDelete();

        Content::withoutGlobalScope('not_slot')->updateOrCreate(
            ['slot' => $key],
            [
                'type'        => 'page',
                'category_id' => null,
                'group_id'    => null,
                'cover_id'    => null,
                'slug'        => Narrative::reservedSlug($key),
                'title'       => $def['label'],
                'summary'     => $summary,
                'body'        => $body,
                'status'      => 'published',
                'published_at' => now(),
                'owner'       => 'narrative-cms',
                'noindex'     => false,
                'external_id' => null,
                'external_source' => null,
                'synced_at'   => null,
            ]
        );
        Narrative::flush();

        return redirect()
            ->route('admin.narrative.index')
            ->with('success', '「' . $def['label'] . '」已保存并同步到前台。');
    }

    public function reset(string $key)
    {
        $def = Narrative::definition($key);
        abort_if(! $def, 404);

        // 批量 forceDelete 不触发模型事件，需手动失效整页缓存与叙事内存
        Content::withoutGlobalScope('not_slot')->where('slot', $key)->forceDelete();
        Narrative::flush();
        PageCache::flush();

        return redirect()
            ->route('admin.narrative.index')
            ->with('success', '「' . $def['label'] . '」已恢复为系统默认文案。');
    }
}
