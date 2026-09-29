{{-- Flash de sessão → toast do kit. Renderize UMA vez no layout:
     <x-flash-toast />. Chaves padronizadas:
       session('status') / session('success') / session('contact_status') → sucesso
       session('error') → erro
     Renderizados já visíveis; o auto-esconder vive em resources/js/ui.js. --}}
@php
    $flashes = collect([
        'status' => 'success',
        'success' => 'success',
        'contact_status' => 'success',
        'error' => 'error',
    ])
        ->map(fn (string $type, string $key): ?array => session()->has($key) ? ['type' => $type, 'message' => session($key)] : null)
        ->filter()
        ->values();
@endphp

@if ($flashes->isNotEmpty())
    <div class="pointer-events-none fixed right-4 bottom-4 z-50 flex flex-col items-end gap-2">
        @foreach ($flashes as $flash)
            <x-toast :type="$flash['type']">{{ $flash['message'] }}</x-toast>
        @endforeach
    </div>
@endif
