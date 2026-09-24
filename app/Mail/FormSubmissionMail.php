<?php

namespace App\Mail;

use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 表单提交通知邮件（P-STEP 18H-2）。
 * --------------------------------------------------
 * 仅在 Form.notification_enabled 且能解析出收件人时由 FormNotificationSender
 * 发送（事务提交之后）；默认 mailer 为 log，未配 SMTP 不报错。
 */
class FormSubmissionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Form $form,
        public FormSubmission $submission,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[' . $this->form->name . '] 新的表单提交',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.form-submission',
            with: [
                'form'       => $this->form,
                'submission' => $this->submission,
                'payload'    => $this->submission->payloadArray(),
            ],
        );
    }
}
