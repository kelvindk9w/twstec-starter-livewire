// =============================================================================
// Interações de UI do kit — vanilla JS, sem dependências, servido pelo Vite
// (CSP-friendly: nada de JS inline nas views).
//
// Convenções data-*:
//   [data-modal-open="id"]  abre o <x-modal id="id">
//   [data-modal-close]      fecha o modal ancestral (backdrop, botões, Esc)
//   [data-toast]            <x-toast> — controlado por showToast()
//   [data-toast-sticky]     toast visível que NÃO auto-esconde (demos)
//   [data-toast-show="id"]  botão que exibe o toast #id (auto-esconde)
//   [data-copy="texto"]     copia o texto; feedback no próprio botão + toast
//   [data-reveal]           scroll-reveal (IntersectionObserver, uma vez)
//   [data-scrollspy]        nav cujos links #âncora ganham aria-current
//                           (pode haver mais de um: coluna + gaveta)
//   [data-side-nav-current] rótulo da barra compacta do <x-side-nav> (mobile)
//   [data-theme-toggle]     cicla o tema: sistema → claro → escuro
//   [data-theme-set="…"]    define o tema diretamente (segmented control)
//   [data-locale-switch]    <select> de idioma — navega para a URL da option
//   [data-password-toggle]  botão "olho" do <x-input type="password">
//   [data-overlay-show="id"] abre o <x-loading-overlay id>; data-overlay-timeout
//                           (ms, opcional) auto-esconde — usado na demo do /ui
//   [data-dropdown]         <x-dropdown> — menu ancorado (idioma, tema, ⋯)
//   [data-dropdown-trigger] botão que abre/fecha o dropdown ancestral
//   [data-file-input]       <x-file-input> — botão do kit + nome do arquivo
//   [data-chart]            <x-chart> — gráfico Chart.js com tokens do tema
// =============================================================================

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// --- Modal / drawer ----------------------------------------------------------
//
// O MESMO motor serve <x-modal> (painel centrado) e <x-drawer> (painel colado
// na borda): as duas raízes são [data-modal] e a diferença é só a geometria do
// painel (.modal-panel / .drawer-panel) — ver app.css.

const PANEL = '.modal-panel, .drawer-panel';

// Elementos que podem receber foco DENTRO do painel aberto.
const FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

// De onde o usuário veio, por modal aberto: ao fechar, o foco VOLTA para lá
// (quem abriu um diálogo com o teclado não pode ser largado no topo da página).
const focusOrigin = new WeakMap();

function openModal(modal) {
    focusOrigin.set(modal, document.activeElement);

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    void modal.offsetWidth; // reflow: a transition parte do estado inicial
    modal.classList.add('is-open');

    // Foco no primeiro alvo do painel (fallback: o próprio painel).
    const panel = modal.querySelector(PANEL);
    const first = panel?.querySelector(FOCUSABLE);
    (first ?? panel)?.focus?.({ preventScroll: true });
}

function closeModal(modal) {
    modal.classList.remove('is-open');

    const hide = () => {
        if (!modal.classList.contains('is-open')) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    };

    modal.querySelector(PANEL)?.addEventListener('transitionend', hide, { once: true });
    setTimeout(hide, 250); // fallback caso a transition não dispare

    const origin = focusOrigin.get(modal);
    if (origin instanceof HTMLElement && document.contains(origin)) {
        origin.focus({ preventScroll: true });
    }
    focusOrigin.delete(modal);
}

// Foco PRESO: Tab dentro de um diálogo aberto circula no próprio painel.
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Tab') return;

    const modal = document.querySelector('[data-modal].is-open');
    if (!modal) return;

    const panel = modal.querySelector(PANEL);
    const targets = [...(panel?.querySelectorAll(FOCUSABLE) ?? [])].filter(
        (el) => el.offsetParent !== null || el === document.activeElement,
    );

    if (targets.length === 0) return;

    const first = targets[0];
    const last = targets[targets.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    } else if (!panel?.contains(document.activeElement)) {
        event.preventDefault();
        first.focus();
    }
});

document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-modal-open]');

    if (opener) {
        const modal = document.getElementById(opener.dataset.modalOpen);
        if (modal) openModal(modal);

        return;
    }

    const closer = event.target.closest('[data-modal-close]');

    if (closer) {
        const modal = closer.closest('[data-modal]');
        if (modal) closeModal(modal);
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;

    document.querySelectorAll('[data-modal].is-open').forEach((modal) => {
        // Modal controlado pelo servidor (<x-modal dismiss="…">): o Esc precisa
        // avisar o Livewire, senão o painel some da tela e volta no próximo
        // render — o servidor continua achando que está aberto.
        const dismiss = modal.querySelector('[data-modal-dismiss]');

        if (dismiss) {
            dismiss.click();

            return;
        }

        closeModal(modal);
    });
});

// --- Toast -------------------------------------------------------------------

const toastTimers = new WeakMap();

function showToast(toast, timeout = 3000) {
    clearTimeout(toastTimers.get(toast));
    toast.classList.remove('hidden');
    void toast.offsetWidth;
    toast.classList.add('is-visible');

    if (timeout > 0) {
        toastTimers.set(toast, setTimeout(() => hideToast(toast), timeout));
    }
}

function hideToast(toast) {
    clearTimeout(toastTimers.get(toast));
    toast.classList.remove('is-visible');

    const hide = () => {
        if (!toast.classList.contains('is-visible')) toast.classList.add('hidden');
    };

    toast.addEventListener('transitionend', hide, { once: true });
    setTimeout(hide, 300);
}

// Helper global: exibe uma mensagem em qualquer <x-toast> já renderizado.
window.twsToast = (id, message = null, timeout = 3000) => {
    const toast = document.getElementById(id);
    if (!toast) return;

    if (message !== null) {
        const target = toast.querySelector('[data-toast-message]');
        if (target) target.textContent = message;
    }

    showToast(toast, timeout);
};

// Flash de sessão: toasts renderizados JÁ visíveis (sem .hidden — ex.:
// confirmação do formulário de contato) entram visíveis e auto-escondem.
// Ficam mais tempo que um toast de ação (8s vs 3s): a página acabou de
// recarregar no topo e quem enviou o formulário lá embaixo ainda está se
// situando — com 4s a confirmação sumia antes de ser lida.
// [data-toast-sticky] desliga o auto-esconder (demo estática no /ui).
const FLASH_TOAST_TIMEOUT = 8000;

document.querySelectorAll('[data-toast]:not(.hidden)').forEach((toast) => {
    toast.classList.add('is-visible');

    if (! toast.hasAttribute('data-toast-sticky')) {
        toastTimers.set(toast, setTimeout(() => hideToast(toast), FLASH_TOAST_TIMEOUT));
    }
});

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-toast-show]');

    if (trigger) {
        const toast = document.getElementById(trigger.dataset.toastShow);
        if (toast) showToast(toast);
    }
});

// --- Copiar para a área de transferência --------------------------------------

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy]');
    if (!button) return;

    try {
        await navigator.clipboard.writeText(button.dataset.copy);
    } catch {
        return; // clipboard indisponível (ex.: permissão negada) — silencia
    }

    // Feedback no próprio botão (troca o rótulo por ~1,5s).
    const label = button.querySelector('[data-copy-label]') ?? button;

    if (!label.dataset.originalText) label.dataset.originalText = label.textContent;

    label.textContent = button.dataset.copiedText ?? label.dataset.originalText;
    button.classList.add('is-copied');

    setTimeout(() => {
        label.textContent = label.dataset.originalText;
        button.classList.remove('is-copied');
    }, 1500);

    // Feedback extra via toast do kit: o botão pode apontar um toast
    // (data-copy-toast="id|mensagem"); senão, usa o #clipboard-toast da página.
    if (button.dataset.copyToast) {
        const [id, ...rest] = button.dataset.copyToast.split('|');
        window.twsToast(id, rest.length ? rest.join('|') : null);
    } else {
        const pageToast = document.getElementById('clipboard-toast');
        if (pageToast) showToast(pageToast);
    }
});

// --- Scroll-reveal ------------------------------------------------------------

const revealTargets = document.querySelectorAll('[data-reveal]');

if (revealTargets.length && !reduceMotion && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            }
        },
        { rootMargin: '0px 0px -8% 0px' },
    );

    revealTargets.forEach((el) => {
        el.classList.add('reveal');
        observer.observe(el);
    });

    // REDE DE SEGURANÇA: o reveal esconde conteúdo com opacity:0 via JS. Se o
    // IntersectionObserver não disparar (aba em segundo plano, captura de tela
    // sem scroll, layout que nunca cruza o limiar), a página fica EM BRANCO.
    // Depois de 2s, tudo o que sobrou aparece — animação é enfeite, conteúdo
    // não é opcional.
    setTimeout(() => {
        revealTargets.forEach((el) => el.classList.add('is-visible'));
        observer.disconnect();
    }, 2000);
}

// --- Scrollspy (índice do <x-side-nav>) ---------------------------------------
//
// O índice existe em DOIS markups na mesma página (a coluna do desktop e a
// gaveta do mobile), então o spy marca todos os [data-scrollspy] de uma vez —
// abrir a gaveta no meio da página tem de mostrar onde o leitor está, não o
// topo do documento. O rótulo da barra compacta ([data-side-nav-current])
// acompanha a seção atual pelo mesmo caminho.

const spyNavs = [...document.querySelectorAll('[data-scrollspy]')];

if (spyNavs.length && 'IntersectionObserver' in window) {
    // id da seção => todos os links que apontam para ela (um por markup).
    const links = new Map();

    for (const nav of spyNavs) {
        for (const link of nav.querySelectorAll('a[href^="#"]')) {
            const id = link.getAttribute('href').slice(1);
            if (!links.has(id)) links.set(id, []);
            links.get(id).push(link);
        }
    }

    const currentLabels = [...document.querySelectorAll('[data-side-nav-current]')];

    const spy = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                const targets = links.get(entry.target.id);
                if (!targets || !entry.isIntersecting) continue;

                links.forEach((group) => group.forEach((l) => l.removeAttribute('aria-current')));
                targets.forEach((l) => l.setAttribute('aria-current', 'true'));

                const label = targets[0].textContent.trim();
                currentLabels.forEach((el) => {
                    el.textContent = label;
                });
            }
        },
        { rootMargin: '-20% 0px -70% 0px' },
    );

    links.forEach((_, id) => {
        const section = document.getElementById(id);
        if (section) spy.observe(section);
    });
}

// --- Tema claro/escuro/sistema -------------------------------------------------
//
// 3 estados: system (padrão, segue prefers-color-scheme) → light → dark.
// Resolução: localStorage 'theme' (dispositivo) → data-theme-default no <html>
// (preferência da conta, renderizada server-side) → 'system'. O script inline
// do <head> (partials/theme-script) aplica a classe ANTES do primeiro paint;
// aqui ficam o toggle, o segmented control e a persistência.

const themeMedia = window.matchMedia('(prefers-color-scheme: dark)');

function themeSetting() {
    let stored = null;
    try { stored = localStorage.getItem('theme'); } catch { /* storage indisponível */ }

    return ['light', 'dark', 'system'].includes(stored)
        ? stored
        : (document.documentElement.dataset.themeDefault || 'system');
}

function applyTheme(setting) {
    const dark = setting === 'dark' || (setting === 'system' && themeMedia.matches);
    document.documentElement.classList.toggle('dark', dark);

    // Ícones do toggle e estado do segmented control refletem a PREFERÊNCIA
    // (não o resultado resolvido): em 'system' mostramos o monitor.
    document.querySelectorAll('[data-theme-icon]').forEach((icon) => {
        icon.classList.toggle('hidden', icon.dataset.themeIcon !== setting);
    });
    document.querySelectorAll('[data-theme-set]').forEach((button) => {
        const active = button.dataset.themeSet === setting;
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
        button.classList.toggle('is-active', active);
    });

    // Dropdown de tema (<x-theme-toggle>): o ✓ marca o estado escolhido.
    document.querySelectorAll('[data-theme-check]').forEach((check) => {
        check.classList.toggle('hidden', check.dataset.themeCheck !== setting);
    });
}

function setTheme(setting) {
    try { localStorage.setItem('theme', setting); } catch { /* silencia */ }
    applyTheme(setting);

    // Logado: persiste na conta (padrão entre dispositivos) — fire-and-forget.
    if (document.body.hasAttribute('data-authenticated')) {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        fetch('/settings/theme', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token ?? '',
                Accept: 'application/json',
            },
            body: JSON.stringify({ theme: setting }),
            keepalive: true,
        }).catch(() => {});
    }
}

function cycleTheme() {
    const order = { system: 'light', light: 'dark', dark: 'system' };
    setTheme(order[themeSetting()] ?? 'system');
}

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-theme-toggle]');
    if (toggle) { cycleTheme(); return; }

    const setter = event.target.closest('[data-theme-set]');
    if (setter) setTheme(setter.dataset.themeSet);
});

// Mudança do tema do SO reflete ao vivo quando a preferência é 'system'.
themeMedia.addEventListener('change', () => {
    if (themeSetting() === 'system') applyTheme('system');
});

applyTheme(themeSetting());

// --- Dropdown ancorado (idioma, tema, menu de ações) ----------------------------
//
// <x-dropdown>: um <button data-dropdown-trigger> e um painel .dropdown-menu
// dentro de [data-dropdown]. Fecha no clique fora, no Esc e ao escolher um
// item. Teclado: setas percorrem os itens, Home/End vão às pontas.
//
// O seletor de idioma (<x-locale-switcher>) é este dropdown com links reais —
// cada item é uma URL da rota locale.switch, então funciona com o teclado, no
// menu de contexto do navegador e sem depender de fonte de emoji.

function closeDropdown(dropdown) {
    dropdown.classList.remove('is-open');
    dropdown.querySelector('[data-dropdown-trigger]')?.setAttribute('aria-expanded', 'false');
}

function openDropdown(dropdown) {
    document.querySelectorAll('[data-dropdown].is-open').forEach((other) => {
        if (other !== dropdown) closeDropdown(other);
    });

    dropdown.classList.add('is-open');
    dropdown.querySelector('[data-dropdown-trigger]')?.setAttribute('aria-expanded', 'true');
}

function dropdownItems(dropdown) {
    return [...dropdown.querySelectorAll('[data-dropdown-menu] a, [data-dropdown-menu] button')];
}

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-dropdown-trigger]');

    if (trigger) {
        const dropdown = trigger.closest('[data-dropdown]');
        if (!dropdown) return;

        if (dropdown.classList.contains('is-open')) {
            closeDropdown(dropdown);
        } else {
            openDropdown(dropdown);
        }

        return;
    }

    // Clique fora (ou num item do menu) fecha o que estiver aberto.
    const inside = event.target.closest('[data-dropdown]');

    document.querySelectorAll('[data-dropdown].is-open').forEach((dropdown) => {
        if (dropdown !== inside || event.target.closest('[data-dropdown-menu]')) {
            closeDropdown(dropdown);
        }
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        document.querySelectorAll('[data-dropdown].is-open').forEach((dropdown) => {
            closeDropdown(dropdown);
            dropdown.querySelector('[data-dropdown-trigger]')?.focus();
        });

        return;
    }

    const dropdown = event.target.closest?.('[data-dropdown]');
    if (!dropdown) return;

    const isTrigger = event.target.closest('[data-dropdown-trigger]') !== null;

    if (isTrigger && (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ')) {
        if (!dropdown.classList.contains('is-open')) {
            event.preventDefault();
            openDropdown(dropdown);
            dropdownItems(dropdown)[0]?.focus();
        }

        return;
    }

    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;

    const items = dropdownItems(dropdown);
    if (items.length === 0) return;

    event.preventDefault();

    const current = items.indexOf(document.activeElement);
    const next = {
        ArrowDown: current < 0 ? 0 : (current + 1) % items.length,
        ArrowUp: current <= 0 ? items.length - 1 : current - 1,
        Home: 0,
        End: items.length - 1,
    }[event.key];

    items[next].focus();
});

// --- Seletor de arquivo do kit ---------------------------------------------------
//
// <x-file-input>: o chrome nativo do <input type="file"> ("Choose File / No
// file chosen") é desenhado e TRADUZIDO pelo sistema operacional — num kit que
// entrega pt-BR/en/es, ele é a única coisa em inglês na tela. Aqui o input
// fica escondido e quem aparece é o botão do kit + o nome do arquivo.

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-file-trigger]');
    if (!trigger) return;

    trigger.closest('[data-file-input]')?.querySelector('input[type="file"]')?.click();
});

document.addEventListener('change', (event) => {
    const input = event.target.closest('[data-file-input] input[type="file"]');
    if (!input) return;

    const field = input.closest('[data-file-input]');
    const name = field?.querySelector('[data-file-name]');
    if (!name) return;

    name.textContent = input.files?.[0]?.name ?? name.dataset.emptyText ?? '';
});

// --- Senha: botão "olho" do <x-input type="password"> ---------------------------

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-password-toggle]');
    if (!toggle) return;

    const input = toggle.parentElement?.querySelector('input');
    if (!input) return;

    const revealed = input.type === 'password';
    input.type = revealed ? 'text' : 'password';

    toggle.setAttribute('aria-label', revealed ? toggle.dataset.labelHide : toggle.dataset.labelShow);
    toggle.setAttribute('aria-pressed', revealed ? 'true' : 'false');
    toggle.querySelector('[data-password-icon="show"]')?.classList.toggle('hidden', revealed);
    toggle.querySelector('[data-password-icon="hide"]')?.classList.toggle('hidden', !revealed);

    // Devolve o foco ao campo sem mover o cursor (UX de formulário).
    input.focus({ preventScroll: true });
});

// --- Overlay de carregamento (USO RESTRITO — ver showcase) ----------------------

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-overlay-show]');
    if (!trigger) return;

    const overlay = document.getElementById(trigger.dataset.overlayShow);
    if (!overlay) return;

    overlay.classList.remove('hidden');
    overlay.classList.add('flex');

    const timeout = Number(trigger.dataset.overlayTimeout ?? overlay.dataset.overlayTimeout ?? 0);
    if (timeout > 0) {
        setTimeout(() => {
            overlay.classList.add('hidden');
            overlay.classList.remove('flex');
        }, timeout);
    }
});
