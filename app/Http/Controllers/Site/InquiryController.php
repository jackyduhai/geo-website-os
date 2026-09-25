<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Forms\FormResolver;
use App\Support\Forms\FormSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 兼容入口 POST /inquiry（P-STEP 18H-2；P-STEP 18L-2b 支持异步 JSON）。
 * --------------------------------------------------
 * 仅作 Compatibility Layer：解析站点默认联系表单后统一进入 FormSubmissionService，
 * 不再拥有写死字段 / 校验 / 保存逻辑（禁止第二套实现）。无可用表单时 404。
 * 异步请求返回 {success,message}；传统请求保持 back()->with() 整页回退。
 */
class InquiryController extends Controller
{
    public function store(Request $request, FormResolver $resolver, FormSubmissionService $service): JsonResponse|RedirectResponse
    {
        $form = $resolver->defaultContact();

        if (! $form) {
            abort(404);
        }

        $service->record($form, $request);

        $message = filled($form->success_message) ? $form->success_message : __('ui.form_success');

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return back()->with('lead_success', $message);
    }
}