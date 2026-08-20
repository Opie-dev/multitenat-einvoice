<x-mail::message>
# Sign in to Billplz e-Invoice

Hi {{ $name }},

Click the button below to sign in. This link is valid for 15 minutes and can only be used once.

<x-mail::button :url="$url">
Sign in
</x-mail::button>

If you didn't request this link, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
