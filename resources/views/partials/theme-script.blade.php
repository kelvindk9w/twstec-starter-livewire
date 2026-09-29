{{-- Tema claro/escuro/sistema — SEM flash de tema errado no carregamento.
     Script inline mínimo proposital no <head> (antes do primeiro paint):
     resolve localStorage (dispositivo) → data-theme-default (conta, quando
     logado) → 'system'. A CSP base do kit já permite script 'unsafe-inline'
     (config/security.php) — o JS rico fica fora (resources/js/ui.js). --}}
<script>
    (function () {
        var stored = null;
        try { stored = localStorage.getItem('theme'); } catch (e) { /* storage indisponível */ }
        var pref = (stored === 'light' || stored === 'dark' || stored === 'system')
            ? stored
            : (document.documentElement.dataset.themeDefault || 'system');
        var dark = pref === 'dark'
            || (pref === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', dark);
    })();
</script>
