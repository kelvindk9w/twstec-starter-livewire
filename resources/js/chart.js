// =============================================================================
// Gráficos do kit (<x-chart>) — Chart.js servido pelo Vite, dentro da CSP.
//
// Por que Chart.js e não um <canvas> à mão: eixos, ticks, tooltip acessível e
// responsividade são trabalho resolvido — e o pacote não usa eval, então roda
// sob a CSP estrita do painel (script-src 'self'), sem exceção de rota.
//
// Contrato do componente (resources/views/components/chart.blade.php):
//   <canvas data-chart="line"
//           data-chart-labels='["01/09", …]'
//           data-chart-values='[12, …]'
//           data-chart-label="Requisições">
//
// As CORES saem dos tokens do tema em runtime (getComputedStyle), não de
// literais: trocar --color-brand no theme.css troca o gráfico junto, e o
// tema escuro é lido do mesmo lugar que o resto da interface.
// =============================================================================

import {
    Chart,
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    CategoryScale,
    Filler,
    Tooltip,
} from 'chart.js';

Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, Filler, Tooltip);

const instances = new WeakMap();

function token(name, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    return value === '' ? fallback : value;
}

function palette() {
    return {
        brand: token('--color-brand', '#171717'),
        muted: token('--color-text-muted', '#6b7280'),
        border: token('--color-border', '#e5e7eb'),
        surface: token('--color-surface', '#ffffff'),
    };
}

function render(canvas) {
    instances.get(canvas)?.destroy();

    let labels = [];
    let values = [];

    try {
        labels = JSON.parse(canvas.dataset.chartLabels ?? '[]');
        values = JSON.parse(canvas.dataset.chartValues ?? '[]');
    } catch {
        return; // dado malformado: melhor um espaço vazio do que um erro na tela
    }

    const colors = palette();

    // Área sob a linha: um degradê da marca até transparente. Preenchimento
    // sólido chapa o gráfico; o degradê dá volume sem roubar leitura do eixo.
    let gradient = 'transparent';

    try {
        const context = canvas.getContext('2d');
        gradient = context.createLinearGradient(0, 0, 0, canvas.clientHeight || 220);
        gradient.addColorStop(0, `color-mix(in oklab, ${colors.brand} 22%, transparent)`);
        gradient.addColorStop(1, `color-mix(in oklab, ${colors.brand} 0%, transparent)`);
    } catch {
        gradient = 'transparent'; // navegador sem color-mix no canvas: só a linha
    }

    instances.set(
        canvas,
        new Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: canvas.dataset.chartLabel ?? '',
                        data: values,
                        borderColor: colors.brand,
                        backgroundColor: gradient,
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        pointHoverBackgroundColor: colors.brand,
                        pointHoverBorderColor: colors.surface,
                        pointHoverBorderWidth: 2,
                        tension: 0.35,
                        fill: true,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 400 },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    tooltip: {
                        backgroundColor: colors.brand,
                        titleColor: colors.surface,
                        bodyColor: colors.surface,
                        padding: 10,
                        displayColors: false,
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { color: colors.border },
                        ticks: {
                            color: colors.muted,
                            maxRotation: 0,
                            autoSkipPadding: 24,
                            font: { size: 11 },
                        },
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: colors.border },
                        ticks: { color: colors.muted, precision: 0, maxTicksLimit: 5, font: { size: 11 } },
                    },
                },
            },
        }),
    );
}

function renderAll() {
    document.querySelectorAll('canvas[data-chart]').forEach(render);
}

renderAll();

// Troca de tema (claro ↔ escuro) = novos tokens: redesenha com as cores novas.
new MutationObserver(renderAll).observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['class'],
});

// Navegação Livewire (wire:navigate) e re-render de componente: o canvas volta
// ao DOM sem passar pelo load da página.
document.addEventListener('livewire:navigated', renderAll);
