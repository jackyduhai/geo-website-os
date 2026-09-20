<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * 媒体库：上传到 public 磁盘，记录尺寸与 alt（alt 对图片检索与无障碍必需）
 */
class MediaController extends Controller
{
    public function index(Request $request): View
    {
        $query = Media::latest();
        if ($type = $request->get('type')) {
            if ($type === 'image') {
                $query->where('mime', 'like', 'image/%');
            } else {
                $query->where('mime', 'not like', 'image/%');
            }
        }

        return view('admin.display.media', [
            'items' => $query->paginate(24),
            'type'  => $type,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // 安全：禁止 svg 上传——SVG 可内嵌 <script>/onload，经 public/storage 以
        // image/svg+xml 直出时在浏览器执行，构成存储型 XSS。矢量图请用 PNG/WebP；
        // 如确需 SVG，须先引入专业净化库并以安全 Content-Type 提供。（P-STEP 14）
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx,mp4'],
            'alt'  => ['nullable', 'string', 'max:200'],
        ]);

        $file = $request->file('file');
        $path = \App\Support\ImageOptimizer::store($file, 'media/' . date('Ym'), \App\Support\ImageOptimizer::MAXW_CONTENT);
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
            'alt'           => $request->input('alt'),
            'uploaded_by'   => $request->user()?->id,
        ]);
        AuditLog::record('media.uploaded', '上传媒体：' . $media->original_name, [], 'media', $media->id);

        return back()->with('success', '已上传：' . $media->original_name);
    }

    /**
     * 正文编辑器内联插图：供内容编辑工具条异步上传，返回 Markdown 可直接插入的图片地址。
     * 仅接受图片、限制大小，入媒体库统一管理；与封面/幻灯走同一套磁盘与尺寸记录。
     */
    public function uploadInline(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp,gif'],
            'alt'  => ['nullable', 'string', 'max:200'],
        ]);

        $file = $request->file('file');
        $path = \App\Support\ImageOptimizer::store($file, 'content/' . date('Ym'), \App\Support\ImageOptimizer::MAXW_CONTENT);
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
            'alt'           => $validated['alt'] ?? null,
            'uploaded_by'   => $request->user()?->id,
        ]);
        AuditLog::record('media.uploaded', '正文内联上传：' . $media->original_name, [], 'media', $media->id);

        return response()->json([
            'ok'     => true,
            'url'    => $media->url(),
            'alt'    => $media->alt ?? '',
            'id'     => $media->id,
            'width'  => $width,
            'height' => $height,
        ]);
    }

    public function update(Request $request, Media $media): RedirectResponse
    {
        $data = $request->validate([
            'alt'   => ['nullable', 'string', 'max:200'],
            'title' => ['nullable', 'string', 'max:200'],
        ]);
        $media->update($data);

        return back()->with('success', '媒体信息已更新');
    }

    public function destroy(Media $media): RedirectResponse
    {
        Storage::disk($media->disk)->delete($media->path);
        $media->delete();

        return back()->with('success', '媒体已删除');
    }
}
