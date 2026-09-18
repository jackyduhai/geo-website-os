<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Inquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 后台客户留言跟进
 */
class InquiryController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'new');

        $query = Inquiry::query();
        if ($status && array_key_exists($status, Inquiry::STATUS_LABEL)) {
            $query->where('status', $status);
        }
        $items = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        $counts = [
            'new'      => Inquiry::where('status', 'new')->count(),
            'handled'  => Inquiry::where('status', 'handled')->count(),
            'archived' => Inquiry::where('status', 'archived')->count(),
        ];

        return view('admin.inquiries.index', compact('items', 'status', 'counts'));
    }

    public function handle(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $data = $request->validate([
            'handle_note' => ['nullable', 'string', 'max:1000'],
            'status'      => ['required', 'in:new,handled,archived'],
        ]);

        $inquiry->update([
            'status'      => $data['status'],
            'handle_note' => $data['handle_note'] ?? $inquiry->handle_note,
            'handled_at'  => $data['status'] === 'handled' ? now() : $inquiry->handled_at,
        ]);

        AuditLog::record('inquiry.handle', "留言 #{$inquiry->id} 标记为「" . Inquiry::STATUS_LABEL[$data['status']] . '」');

        return back()->with('success', '留言状态已更新。');
    }

    public function destroy(Inquiry $inquiry): RedirectResponse
    {
        $id = $inquiry->id;
        $inquiry->delete();
        AuditLog::record('inquiry.delete', "删除留言 #{$id}");

        return back()->with('success', '留言已删除。');
    }
}
