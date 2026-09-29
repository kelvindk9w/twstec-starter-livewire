// Checagem interativa rápida: modal abre/fecha e toast aparece no /ui.
import { chromium } from '@playwright/test';

const browser = await chromium.launch();
const page = await browser.newPage({ baseURL: 'http://localhost:8180' });
await page.goto('/ui', { waitUntil: 'networkidle' });

const modal = page.locator('#showcase-modal');
await page.getByRole('button', { name: 'Abrir modal' }).click();
console.log('modal visível após clique:', await modal.isVisible());
await page.keyboard.press('Escape');
await page.waitForTimeout(400); // saída animada (transition, ~160ms) antes de ocultar
console.log('modal oculto após Esc:', !(await modal.isVisible()));

await page.getByRole('button', { name: 'Disparar toast' }).click();
const toast = page.locator('#showcase-toast');
console.log('toast visível após clique:', await toast.isVisible());
await page.waitForTimeout(3500);
console.log('toast oculto após 3s:', !(await toast.isVisible()));

await page.getByRole("button", { name: "Abrir modal" }).click();
await page.waitForTimeout(400); // espera a entrada animada do modal
await page.screenshot({ path: "test-results/shots/modal-aberto.png" });
await browser.close();
