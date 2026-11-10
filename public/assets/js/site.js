/**
 * site.js
 * Comportamentos das páginas de conta, painel e admin (sem scripts inline, CSP estrita):
 * menu mobile, confirmações, copiar link, medidor de senha e QR Codes do painel.
 */
import { qrOptions } from './qr-options.js?v=2.0.0';

const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

/* Menu mobile */
const toggle = document.querySelector('.nav-toggle');
const nav = document.querySelector('#menu-principal');
toggle?.addEventListener('click', () => {
  const open = toggle.getAttribute('aria-expanded') !== 'true';
  toggle.setAttribute('aria-expanded', String(open));
  nav.classList.toggle('is-open', open);
});

/* Confirmação antes de ações destrutivas */
$$('form[data-confirm]').forEach((form) => {
  form.addEventListener('submit', (e) => {
    if (!window.confirm(form.dataset.confirm)) e.preventDefault();
  });
});

/* Copiar para a área de transferência */
$$('[data-copy]').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const input = document.getElementById(btn.dataset.copy);
    try {
      await navigator.clipboard.writeText(input.value);
    } catch {
      input.select();
      document.execCommand('copy');
    }
    const old = btn.textContent;
    btn.textContent = 'Copiado!';
    setTimeout(() => (btn.textContent = old), 1500);
  });
});

/* Medidor simples de força da senha (orientativo; a validação real é no servidor) */
$$('input[data-strength]').forEach((input) => {
  const bar = input.parentElement.querySelector('.strength span');
  if (!bar) return;
  input.addEventListener('input', () => {
    const v = input.value;
    let score = 0;
    if (v.length >= 10) score++;
    if (v.length >= 14) score++;
    if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
    if (/\d/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    if (v.length < 10) score = Math.min(score, 1);
    bar.style.width = `${(score / 5) * 100}%`;
    bar.dataset.level = score <= 1 ? 'weak' : score <= 3 ? 'ok' : 'strong';
  });
});

/* QR Codes do painel (renderizados no navegador com a mesma biblioteca do gerador) */
const QRCodeStyling = window.QRCodeStyling;
if (QRCodeStyling) {
  $$('[data-qr]').forEach((el) => {
    try {
      const { data, style } = JSON.parse(el.dataset.qr);
      const qr = new QRCodeStyling(qrOptions({ data, style, size: 300, type: 'svg' }));
      qr.append(el);
      el.qrInfo = { data, style };
    } catch (err) {
      console.error(err);
    }
  });

  $$('[data-download]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const target = document.getElementById(btn.dataset.target);
      if (!target?.qrInfo) return;
      const ext = btn.dataset.download;
      const { data, style } = target.qrInfo;
      const qr = new QRCodeStyling(qrOptions({ data, style, size: 1024, type: ext === 'png' ? 'canvas' : 'svg' }));
      btn.disabled = true;
      try {
        await qr.download({ name: btn.dataset.name || 'qrcode', extension: ext });
      } finally {
        btn.disabled = false;
      }
    });
  });
}
