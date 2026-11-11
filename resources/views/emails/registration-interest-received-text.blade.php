{{ $interest->name }} from {{ $interest->company_name }} just registered interest in Alima.

Name: {{ $interest->name }}
Email: {{ $interest->email }}
Phone: {{ $interest->phone ?? '—' }}
Company: {{ $interest->company_name }}
Location: {{ $interest->location ?? '—' }}

Country (from IP): {{ $interest->country ?? 'unknown' }}{{ $interest->city ? ' · '.$interest->city : '' }}
Timezone: {{ $interest->timezone ?? 'unknown' }}
Came from: {{ $source }}

Manage it here: {{ $url }}

Reply to this email to reach them directly. We promised a reply by {{ $replyBy->format('D j M, H:i') }} UK time.
