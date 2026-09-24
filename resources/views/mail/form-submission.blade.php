{{-- 表单提交通知（纯文本）。所有用户输入经 {{ }} 转义，防止邮件内容注入。 --}}
新的表单提交
表单：{{ $form->name }}
时间：{{ $submission->created_at }}
语言：{{ $submission->locale }}
@foreach($payload as $key => $value)
{{ $key }}: @if(is_array($value)){{ implode(', ', array_map('strval', $value)) }}@else{{ $value }}@endif
@endforeach
来源页：{{ $submission->source_page }}
落地页：{{ $submission->landing_url }}
