{{-- Telas de autenticação (login, cadastro, recuperação, senha de transação).

     Usam o MESMO cabeçalho e rodapé do site: entrar não é sair da página.
     Antes eram um cartão solto num fundo cinza, sem marca navegável, sem
     seletor de idioma e sem tema — a única tela do produto onde o visitante
     ficava sem saída. O foco continua no formulário: o cabeçalho é discreto e
     o cartão ocupa o centro da área livre.

     O título da aba vem do @section('title') da view filha (yieldContent é a
     forma de ler uma section de dentro de um componente). --}}
<x-layouts.site :title="$__env->yieldContent('title').' — '.platform()->name" background="bg-surface-sunken">
    <main class="flex flex-1 items-center justify-center px-4 py-12">
        <div class="w-full max-w-sm">
            <x-card>
                {{-- O corpo do <x-card> é texto secundário por padrão (é um
                     cartão de conteúdo); aqui dentro mora um formulário, cujo
                     título e rótulos são texto principal. --}}
                <div class="text-gray-900 dark:text-gray-100">
                    {{-- Resumo de erros conforme a estratégia (config/ui.php →
                         error_display); inline é tratado pelos próprios campos. --}}
                    <x-form-errors class="mb-4" />

                    @yield('content')
                </div>
            </x-card>
        </div>
    </main>
</x-layouts.site>
