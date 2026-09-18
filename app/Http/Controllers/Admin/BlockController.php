<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Content;
use App\Models\Media;
use App\Models\PageBlock;
use App\Support\ImageOptimizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 首页整体装修
 *
 * 每个区块可：开关、排序、改标题/副标题；
 * items 型（能力点/车间/流程）可增删条目并选图标；
 * source 型（产品/知识/新闻）可选来源栏目、条数，或手动指定具体内容。
 */
class BlockController extends Controller
{
    public function index(): View
    {
        $blocks = PageBlock::with('category')->forPage('home')->get();
        $categories = Category::orderBy('sort')->get();
        $iconOptions = config('icons');
        $contents = Content::published()->with('category')
            ->orderByDesc('published_at')->get(['id', 'title', 'category_id', 'published_at']);
        // 首屏 / 中部横幅直接在首页装修里就地维护（按排序，含图）
        $heroBanners = Banner::where('position', 'home_top')->orderBy('sort')->with('image')->get();
        $midBanners  = Banner::where('position', 'home_mid')->orderBy('sort')->with('image')->get();

        return view('admin.display.blocks', compact('blocks', 'categories', 'iconOptions', 'contents', 'heroBanners', 'midBanners'));
    }

    public function update(Request $request, PageBlock $block): RedirectResponse
    {
        $validated = $request->validate([
            'title'       => ['nullable', 'string', 'max:120'],
            'subtitle'    => ['nullable', 'string', 'max:200'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'limit'       => ['nullable', 'integer', 'min:1', 'max:50'],
            'sort'        => ['nullable', 'integer'],
            'is_active'   => ['nullable', 'boolean'],
            // 可增删条目
            'items'              => ['nullable', 'array'],
            'items.*.icon'       => ['nullable', 'string', 'max:40'],
            'items.*.image'      => ['nullable', 'string', 'max:255'],
            'items.*.title'      => ['nullable', 'string', 'max:120'],
            'items.*.text'       => ['nullable', 'string', 'max:300'],
            'items.*.link'       => ['nullable', 'string', 'max:255'],
            'items.*.image_file' => ['nullable', 'file', 'max:4096', 'mimes:jpg,jpeg,png,webp,gif,svg'],
            'items.*.image_remove'=> ['nullable', 'boolean'],
            // 首屏/中部横幅内嵌幻灯（直接在首页装修里维护 Banner，无需跳转）
            'slides'             => ['nullable', 'array'],
            'slides.*.id'        => ['nullable', 'integer'],
            'slides.*.title'     => ['nullable', 'string', 'max:120'],
            'slides.*.subtitle'  => ['nullable', 'string', 'max:300'],
            'slides.*.link'      => ['nullable', 'string', 'max:255'],
            'slides.*.link_text' => ['nullable', 'string', 'max:40'],
            'slides.*.sort'      => ['nullable', 'integer'],
            'slides.*.is_active' => ['nullable', 'boolean'],
            'slides.*.remove'    => ['nullable', 'boolean'],
            'slides.*.image_id'  => ['nullable', 'integer'],
            'slides.*.image_file'=> ['nullable', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp,gif'],
            'new_slides'         => ['nullable', 'array'],
            'new_slides.*.title'     => ['nullable', 'string', 'max:120'],
            'new_slides.*.subtitle'  => ['nullable', 'string', 'max:300'],
            'new_slides.*.link'      => ['nullable', 'string', 'max:255'],
            'new_slides.*.link_text' => ['nullable', 'string', 'max:40'],
            'new_slides.*.image_file'=> ['nullable', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp,gif'],
            // 手动指定内容
            'pick_ids'           => ['nullable', 'array'],
            'pick_ids.*'         => ['integer', 'exists:contents,id'],
            // 首屏模式：A=参数卡（默认）/ C=一体化主视觉 / B=图片 Banner（读 Banner 轮播）
            'hero_mode'          => ['nullable', 'in:A,B,C'],
            'hero_autoplay'      => ['nullable', 'boolean'],
            'hero_lead'          => ['nullable', 'string', 'max:500'],
        ]);

        $data = [
            'title'       => $validated['title'] ?? null,
            'subtitle'    => $validated['subtitle'] ?? null,
            'sort'        => (int) ($validated['sort'] ?? $block->sort),
            'is_active'   => $request->boolean('is_active'),
            'category_id' => $validated['category_id'] ?? null,
            'limit'       => (int) ($validated['limit'] ?? $block->limit ?: 6),
        ];

        $iconKeys = array_keys((array) config('icons'));
        $kind = $block->kind();

        if (in_array($kind, ['items', 'steps'], true)) {
            $items = [];
            $rows  = (array) $request->input('items', []);
            foreach ($rows as $idx => $row) {
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    continue; // 空行不保存
                }
                $icon = in_array(($row['icon'] ?? ''), $iconKeys, true) ? $row['icon'] : null;

                // 自定义图片/图标：直接上传即入媒体库；未上传则保留已存图，勾选移除才清空
                $image = trim((string) ($row['image'] ?? ''));
                if (! empty($row['image_remove'])) {
                    $image = '';
                }
                $file = $request->file("items.$idx.image_file");
                if ($file && $file->isValid()) {
                    $path = ImageOptimizer::store($file, 'blocks/' . date('Ym'), ImageOptimizer::MAXW_CONTENT);
                    $storedPath = \Illuminate\Support\Facades\Storage::disk('public')->path($path);
                    [$width, $height] = @getimagesize($storedPath) ?: [null, null];
                    $media = Media::create([
                        'disk'          => 'public',
                        'path'          => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime'          => $file->getClientMimeType(),
                        'size'          => is_file($storedPath) ? filesize($storedPath) : $file->getSize(),
                        'width'         => $width,
                        'height'        => $height,
                        'alt'           => $title,
                        'uploaded_by'   => $request->user()?->id,
                    ]);
                    $image = $media->url();
                }

                $entry = [
                    'icon'  => $icon,
                    'title' => $title,
                    'text'  => trim((string) ($row['text'] ?? '')),
                ];
                $link = trim((string) ($row['link'] ?? ''));
                if ($link !== '') {
                    $entry['link'] = $link; // 场景等条目可配跳转
                }
                if ($image !== '') {
                    $entry['image'] = $image; // 有自定义图时前台优先用图，缺省回退线性图标
                }
                // 保留默认条目自带的派生字段（场景 tags/reveal、合作 points/cta、剪影 sub 等），避免一保存就丢失
                foreach ($row as $ek => $ev) {
                    if (in_array($ek, ['icon', 'image', 'image_file', 'image_remove', 'title', 'text', 'link'], true)) {
                        continue;
                    }
                    if (is_array($ev)) {
                        $entry[$ek] = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $ev), fn ($v) => $v !== ''));
                    } else {
                        $ev = trim((string) $ev);
                        if ($ev !== '') {
                            $entry[$ek] = $ev;
                        }
                    }
                }
                $items[] = $entry;
            }
            $data['content'] = json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
        } elseif ($kind === 'source') {
            $ids = array_values(array_map('intval', (array) $request->input('pick_ids', [])));
            $data['content'] = json_encode(['ids' => $ids], JSON_UNESCAPED_UNICODE);
        } elseif ($kind === 'hero') {
            // 首屏模式：A 参数卡 / B 全幅轮播 / C 一体化主视觉；autoplay 对 B/C 生效；
            // lead 为 A 模式可编辑说明正文（C/B 每张幻灯各自带标题与正文）
            $cfg = $block->cfg();
            $cfg['mode'] = in_array($request->input('hero_mode'), ['A', 'B', 'C'], true)
                ? $request->input('hero_mode') : 'A';
            $cfg['autoplay'] = $request->boolean('hero_autoplay');
            $cfg['lead'] = trim((string) ($validated['hero_lead'] ?? ''));
            $data['content'] = json_encode($cfg, JSON_UNESCAPED_UNICODE);
            // 首屏幻灯就地维护（B/C 共用 home_top）：增删改、传图、文案、排序、启用
            $this->syncSlides($request, 'home_top');
        } elseif ($kind === 'midbanner') {
            // 中部横幅就地维护（home_mid），无需再去独立 Banner 模块
            $this->syncSlides($request, 'home_mid');
        }

        $block->update($data);

        return back()->with('success', '区块「' . $block->typeLabel() . '」已保存');
    }

    /**
     * 在首页装修内同步某投放位置的幻灯/Banner（home_top / home_mid）。
     * slides 为已存在记录（可改文案/换图/排序/启用/删除），new_slides 为新增（需有图）。
     */
    protected function syncSlides(Request $request, string $position): void
    {
        // 1) 更新 / 删除已有幻灯
        foreach ((array) $request->input('slides', []) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $banner = Banner::where('position', $position)->find($id);
            if (! $banner) {
                continue;
            }
            if (! empty($row['remove'])) {
                $banner->delete();
                continue;
            }
            $banner->title = trim((string) ($row['title'] ?? ''));
            $banner->subtitle = trim((string) ($row['subtitle'] ?? ''));
            $banner->link = trim((string) ($row['link'] ?? ''));
            $banner->link_text = trim((string) ($row['link_text'] ?? ''));
            $banner->sort = (int) ($row['sort'] ?? $banner->sort);
            $banner->is_active = ! empty($row['is_active']);
            if ($file = $request->file("slides.$id.image_file")) {
                if ($file->isValid() && ($mediaId = $this->storeBannerImage($request, $file, "slides.$id.image_file", $banner->title))) {
                    $banner->image_id = $mediaId;
                }
            }
            $banner->save();
        }

        // 2) 新增幻灯（必须带图才创建，避免空幻灯）
        $nextSort = (int) Banner::where('position', $position)->max('sort');
        foreach (array_values((array) $request->file('new_slides', [])) as $i => $fileRow) {
            $file = $fileRow['image_file'] ?? null;
            if (! $file || ! $file->isValid()) {
                continue;
            }
            $fields = (array) $request->input("new_slides.$i", []);
            $title = trim((string) ($fields['title'] ?? ''));
            $mediaId = $this->storeBannerImage($request, $file, "new_slides.$i.image_file", $title);
            if (! $mediaId) {
                continue;
            }
            Banner::create([
                'position'  => $position,
                'image_id'  => $mediaId,
                'title'     => $title,
                'subtitle'  => trim((string) ($fields['subtitle'] ?? '')),
                'link'      => trim((string) ($fields['link'] ?? '')),
                'link_text' => trim((string) ($fields['link_text'] ?? '')),
                'sort'      => ++$nextSort,
                'target'    => 0,
                'is_active' => true,
            ]);
        }
    }

    /** 存储幻灯图片入媒体库，返回 media id。 */
    protected function storeBannerImage(Request $request, $file, string $key, ?string $alt): ?int
    {
        $path = ImageOptimizer::store($file, 'banners/' . date('Ym'), ImageOptimizer::MAXW_BANNER);
        $storedPath = \Illuminate\Support\Facades\Storage::disk('public')->path($path);
        [$width, $height] = @getimagesize($storedPath) ?: [null, null];
        $media = Media::create([
            'disk'          => 'public',
            'path'          => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime'          => $file->getClientMimeType(),
            'size'          => is_file($storedPath) ? filesize($storedPath) : $file->getSize(),
            'width'         => $width,
            'height'        => $height,
            'alt'           => $alt,
            'uploaded_by'   => $request->user()?->id,
        ]);

        return $media->id;
    }
}
