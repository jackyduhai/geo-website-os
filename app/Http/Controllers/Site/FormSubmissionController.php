<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Support\Forms\FormSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 通用表单提交（P-STEP 18H-2）。
 * --------------------------------------------------
 * 路由 forms/{form:slug}/submit：隐式绑定受 Form 站点全局作用域限制（跨站 / 缺失
 * 自动 404）；disabled 表单在此 404；蜜罐命中由 service 返回 null、静默成功。
 * 成功消息取 Form.success_message，缺省回退语言包。
 */
class FormSubmissionController extends Controller
{
    public function submit(
        Request $request,
        Form $form,
        FormSubmissionService $service,
    ): RedirectResponse {
        if (! $form->isEnabled()) {
            abort(404);
        }

        $service->record($form, $request);

        return back()->with(
            'lead_success',
            filled($form->success_message) ? $form->success_message : __('ui.form_success')
        );
    }
}
