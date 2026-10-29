<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.frontend_url', config('app.url'))">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
Notificação automática gerada pelo **SGDE (Sistema de Gestão de Escalas)**. Por favor, não responda a este email.  
SGDE &bull; © {{ date('Y') }} {{ config('app.name') }}. Todos os direitos reservados.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>