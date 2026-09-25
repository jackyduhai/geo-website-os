<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Support\Forms\FormSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 通用表单提交（P-STEP 18H-2；P-STEP 18L-2b 支持异步 JSON）。
 * --------------------------------------------------
 * 路由 forms/{form:slug}/submit：隐式绑定受 Form 站点全局作用域限制（跨站 / 缺失
 * 自动 404）；disabled 表单在此 404；蜜罐命中由 service 返回 null、静默成功。
 * 异步请求（Accept: JSON）返回 {success,message}；校验失败由 ValidationException
 * 自动渲染为 422 {errors}。传统请求保持 back()->with() 整页回退（渐进增强）。
 */
class FormSubmissionController extends Controller
{
    public function submit(Request $request, Form $form, FormSubmissionService $service): JsonResponse|RedirectResponse
    {
        if (! $form->isEnabled()) {
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