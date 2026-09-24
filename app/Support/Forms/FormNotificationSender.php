<?php

namespace App\Support\Forms;

use App\Mail\FormSubmissionMail;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * 表单通知发送器（P-STEP 18H-2，D4）。
 * --------------------------------------------------
 * 在数据库事务提交之后调用（通知失败不得回滚已保存提交）。
 * 收件人只保留两层正式事实源：
 *   1) Form.notification_recipients（每表单可不同）；
 *   2) Site 级 setting「notification_recipients」（统一默认）；
 *   两层都无 → 不发送。不允许 config / 硬编码邮箱作为业务收件人。
 *
 * 发送异常只 Log::warning，不影响响应与数据。
 */
class FormNotificationSender
{
    public function send(Form $form, FormSubmission $submission): void
    {
        if (! $form->notification_enabled) {
            return;
        }

        $recipients = $form->recipientList();

        if ($recipients === []) {
            $siteRaw = Setting::get('notification_recipients', '');
            $recipients = Form::splitRecipients(is_scalar($siteRaw) ? (string) $siteRaw : '');
        }

        if ($recipients === []) {
            return; // 两层都无收件人 → 不发送
        }

        try {
            Mail::to($recipients)->send(new FormSubmissionMail($form, $submission));
        } catch (\Throwable $e) {
            Log::warning('Form notification failed (submission #' . $submission->id . '): ' . $e->getMessage());
        }
    }
}
