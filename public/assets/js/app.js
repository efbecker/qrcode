/**
 * app.js
 * Lógica da interface: seleção de tipo, formulário dinâmico, personalização,
 * pré-visualização em tempo real e download (PNG/SVG) com qr-code-styling.
 *
 * A biblioteca qr-code-styling é carregada localmente (assets/vendor) como
 * script clássico e expõe window.QRCodeStyling.
 */
import { QR_TYPES } from './payloads.js';
import { ICONS } from './icons.js';
import { qrOptions, DEFAULT_STYLE, PREVIEW_SIZE } from './qr-options.js?v=2.0.0';

const QRCodeStyling = window.QRCodeStyling;

/* ------------------------------------------------------------------ helpers */

const $ = (sel, root = document) => root.querySelector(sel);
const HEX_RE = /^#[0-9a-f]{6}$/i;

const debounce = (fn, ms = 200) => {
  let t;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...args), ms);
  };
};

const DOT_SHAPES = [
  ['square', 'Quadrados'],
  ['rounded', 'Arredondados'],
  ['dots', 'Pontos'],
  ['classy', 'Clássico'],
  ['classy-rounded', 'Clássico arred.'],
  ['extra-rounded', 'Extra arred.'],
];

/** Mini prévia de cada formato de ponto (desenho ilustrativo 3x3). */
const shapeIcon = (type) => {
  const cells = [[0, 0], [1, 0], [0, 1], [2, 1], [1, 2], [2, 2]];
  const draw = ([x, y]) => {
    const cx = 4 + x * 8;
    const cy = 4 + y * 8;
    switch (type) {
      case 'dots':
        return `<circle cx="${cx + 3}" cy="${cy + 3}" r="3"/>`;
      case 'rounded':
        return `<rect x="${cx}" y="${cy}" width="6.5" height="6.5" rx="2"/>`;
      case 'extra-rounded':
        return `<rect x="${cx}" y="${cy}" width="6.5" height="6.5" rx="3.2"/>`;
      case 'classy':
        return `<path d="M${cx} ${cy}h6.5v4a2.5 2.5 0 0 1-2.5 2.5H${cx}z"/>`;
      case 'classy-rounded':
        return `<path d="M${cx + 2} ${cy}h4.5v4a2.5 2.5 0 0 1-2.5 2.5H${cx}v-4.5a2 2 0 0 1 2-2z"/>`;
      default:
        return `<rect x="${cx}" y="${cy}" width="6.5" height="6.5"/>`;
    }
  };
  return `<svg viewBox="0 0 30 30" aria-hidden="true" fill="currentColor">${cells.map(draw).join('')}</svg>`;
};

/* ------------------------------------------------------------------ estado */

const state = {
  typeId: QR_TYPES[0].id,
  values: {}, // valores digitados por tipo: { url: {url: '...'}, wifi: {...} }
  style: { ...DEFAULT_STYLE },
  logo: null, // data URL
  built: '', // conteúdo montado a partir do formulário
  payload: '', // o que vai no QR Code (link curto, se salvo com rastreio)
  saved: null, // { source, data, tracked, panelUrl } após salvar no painel
  account: null, // resposta de api/me
};

const el = {
  typeGrid: $('#type-grid'),
  contentForm: $('#content-form'),
  styleForm: $('#style-form'),
  dotsType: $('#dotsType'),
  preview: $('#qr-preview'),
  placeholder: $('#qr-placeholder'),
  status: $('#qr-status'),
  payloadView: $('#payload-view'),
  btnGenerate: $('#btnGenerate'),
  btnPng: $('#btnPng'),
  btnSvg: $('#btnSvg'),
  logoFile: $('#logoFile'),
  logoClear: $('#logoClear'),
  marginOut: $('#marginOut'),
  promoCard: $('#promo-card'),
  saveCard: $('#save-card'),
  saveTitle: $('#saveTitle'),
  saveTrack: $('#saveTrack'),
  trackWrap: $('#trackWrap'),
  trackHint: $('#trackHint'),
  btnSave: $('#btnSave'),
  saveInfo: $('#saveInfo'),
};

const currentType = () => QR_TYPES.find((t) => t.id === state.typeId);
const currentValues = () => (state.values[state.typeId] ||= defaultValues(currentType()));

function defaultValues(type) {
  const v = {};
  type.fields.forEach((f) => {
    v[f.name] = f.type === 'checkbox' ? false : f.value ?? '';
  });
  return v;
}

/* ------------------------------------------------------------------ cards de tipo */

function renderTypeCards() {
  el.typeGrid.innerHTML = QR_TYPES.map(
    (t) => `
      <button type="button" class="type-card" role="radio" data-type="${t.id}"
              aria-checked="${t.id === state.typeId}" tabindex="${t.id === state.typeId ? 0 : -1}">
        <span class="type-icon">${ICONS[t.icon] || ''}</span>
        <span class="type-label">${t.label}</span>
      </button>`
  ).join('');

  el.typeGrid.addEventListener('click', (e) => {
    const card = e.target.closest('.type-card');
    if (card) selectType(card.dataset.type, true);
  });

  // Navegação por teclado (setas) no grupo de rádio
  el.typeGrid.addEventListener('keydown', (e) => {
    const keys = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
    if (!(e.key in keys)) return;
    e.preventDefault();
    const idx = QR_TYPES.findIndex((t) => t.id === state.typeId);
    const next = QR_TYPES[(idx + keys[e.key] + QR_TYPES.length) % QR_TYPES.length];
    selectType(next.id, false);
    el.typeGrid.querySelector(`[data-type="${next.id}"]`).focus();
  });
}

function selectType(id, focusForm) {
  if (!QR_TYPES.some((t) => t.id === id)) return;
  state.typeId = id;
  el.typeGrid.querySelectorAll('.type-card').forEach((c) => {
    const active = c.dataset.type === id;
    c.setAttribute('aria-checked', active);
    c.tabIndex = active ? 0 : -1;
  });
  renderContentForm();
  if (focusForm) el.contentForm.querySelector('input, textarea, select')?.focus({ preventScroll: true });
  if (history.replaceState) history.replaceState(null, '', `#${id}`);
  update();
}

/* ------------------------------------------------------------------ formulário dinâmico */

function fieldHtml(f, value) {
  const id = `f-${state.typeId}-${f.name}`;
  const attrs = [
    `id="${id}"`,
    `name="${f.name}"`,
    f.placeholder ? `placeholder="${f.placeholder}"` : '',
    f.required ? 'required aria-required="true"' : '',
    f.maxlength ? `maxlength="${f.maxlength}"` : '',
    f.inputmode ? `inputmode="${f.inputmode}"` : '',
    f.hint ? `aria-describedby="${id}-hint"` : '',
  ].join(' ');
  const hint = f.hint ? `<small id="${id}-hint" class="hint">${f.hint}</small>` : '';
  const req = f.required ? ' <span class="req" aria-hidden="true">*</span>' : '';
  const cls = `field${f.full ? ' field-full' : ''}`;

  if (f.type === 'checkbox') {
    return `<div class="${cls} field-check" data-field="${f.name}">
      <label class="switch"><input type="checkbox" ${attrs} ${value ? 'checked' : ''}>
      <span class="switch-track" aria-hidden="true"></span><span class="switch-label">${f.label}</span></label>${hint}</div>`;
  }
  let control;
  if (f.type === 'select') {
    control = `<select ${attrs}>${f.options
      .map(([v, l]) => `<option value="${v}" ${v === value ? 'selected' : ''}>${l}</option>`)
      .join('')}</select>`;
  } else if (f.type === 'textarea') {
    control = `<textarea rows="3" ${attrs}></textarea>`;
  } else {
    control = `<input type="${f.type}" ${attrs}>`;
  }
  return `<div class="${cls}" data-field="${f.name}"><label for="${id}">${f.label}${req}</label>${control}${hint}</div>`;
}

function renderContentForm() {
  const type = currentType();
  const values = currentValues();
  el.contentForm.innerHTML = type.fields.map((f) => fieldHtml(f, values[f.name])).join('');
  // Valores de texto atribuídos via DOM (evita problemas de escape no HTML)
  type.fields.forEach((f) => {
    const input = el.contentForm.elements[f.name];
    if (input && f.type !== 'checkbox' && f.type !== 'select') input.value = values[f.name] ?? '';
  });
  applyShowIf();
}

function applyShowIf() {
  const values = currentValues();
  currentType().fields.forEach((f) => {
    if (!f.showIf) return;
    const wrap = el.contentForm.querySelector(`[data-field="${f.name}"]`);
    if (wrap) wrap.hidden = !f.showIf(values);
  });
}

function onContentInput(e) {
  const t = e.target;
  if (!t.name) return;
  currentValues()[t.name] = t.type === 'checkbox' ? t.checked : t.value;
  applyShowIf();
  scheduleUpdate();
}

/* ------------------------------------------------------------------ personalização */

function renderShapeOptions() {
  el.dotsType.innerHTML = DOT_SHAPES.map(
    ([value, label]) => `
      <label class="shape-option">
        <input type="radio" name="dotsType" value="${value}" ${value === state.style.dotsType ? 'checked' : ''}>
        <span class="shape-box">${shapeIcon(value)}<span>${label}</span></span>
      </label>`
  ).join('');
}

/** Sincroniza o formulário de estilo com state.style. */
function syncStyleForm() {
  const s = state.style;
  ['bgColor', 'dotsColor', 'eyeFrameColor', 'eyeCenterColor'].forEach((k) => {
    $(`#${k}`).value = s[k];
    $(`#${k}Hex`).value = s[k].toUpperCase();
  });
  $('#bgTransparent').checked = s.bgTransparent;
  $('#eyeFrameType').value = s.eyeFrameType;
  $('#eyeCenterType').value = s.eyeCenterType;
  $('#ecLevel').value = s.ecLevel;
  $('#margin').value = s.margin;
  $('#exportSize').value = String(s.exportSize);
  el.marginOut.textContent = s.margin;
  el.dotsType.querySelectorAll('input').forEach((r) => (r.checked = r.value === s.dotsType));
  updateTransparentUi();
}

function updateTransparentUi() {
  const off = state.style.bgTransparent;
  $('#bgColor').disabled = off;
  $('#bgColorHex').disabled = off;
  $('#bgColor').closest('.color-field').classList.toggle('is-disabled', off);
  el.preview.classList.toggle('is-transparent', off);
}

function onStyleInput(e) {
  const t = e.target;
  const s = state.style;

  if (t.type === 'color') {
    s[t.id] = t.value;
    $(`#${t.dataset.hexFor}`).value = t.value.toUpperCase();
  } else if (t.classList.contains('hex-input')) {
    let v = t.value.trim();
    if (v && v[0] !== '#') v = `#${v}`;
    const key = t.id.replace(/Hex$/, '');
    const valid = HEX_RE.test(v);
    t.classList.toggle('is-invalid', !valid && v.length >= 7);
    if (!valid) return;
    s[key] = v.toLowerCase();
    $(`#${key}`).value = s[key];
  } else if (t.id === 'bgTransparent') {
    s.bgTransparent = t.checked;
    updateTransparentUi();
  } else if (t.name === 'dotsType') {
    s.dotsType = t.value;
  } else if (t.id === 'margin') {
    s.margin = Number(t.value);
    el.marginOut.textContent = t.value;
  } else if (t.id === 'exportSize') {
    s.exportSize = Number(t.value);
    return; // não afeta a prévia
  } else if (t.id in s) {
    s[t.id] = t.value;
  } else {
    return;
  }
  scheduleUpdate();
}

function onLogoChange() {
  const file = el.logoFile.files?.[0];
  if (!file) return;
  if (file.size > 2 * 1024 * 1024) {
    setStatus('A imagem do logo deve ter no máximo 2 MB.', 'error');
    el.logoFile.value = '';
    return;
  }
  const reader = new FileReader();
  reader.onload = () => {
    state.logo = reader.result;
    el.logoClear.hidden = false;
    // Com logo, a correção de erro alta garante a leitura
    if (state.style.ecLevel === 'L' || state.style.ecLevel === 'M') {
      state.style.ecLevel = 'H';
      $('#ecLevel').value = 'H';
    }
    update();
  };
  reader.readAsDataURL(file);
}

function clearLogo() {
  state.logo = null;
  el.logoFile.value = '';
  el.logoClear.hidden = true;
  update();
}

/* ------------------------------------------------------------------ QR Code */

/** Monta as opções do qr-code-styling para um tamanho em pixels. */
function buildQrOptions(size, type = 'svg') {
  return qrOptions({ data: state.payload, style: state.style, size, type, logo: state.logo });
}

let previewQr = null;

function setStatus(msg, kind = '') {
  el.status.textContent = msg;
  el.status.dataset.kind = kind;
}

function setReady(ready) {
  el.btnPng.disabled = !ready;
  el.btnSvg.disabled = !ready;
  el.placeholder.hidden = ready;
  el.preview.hidden = !ready;
}

function update() {
  state.built = currentType().build(currentValues()) || '';
  // Conteúdo mudou depois de salvar: volta a ser um QR Code novo (ainda não salvo)
  if (state.saved && state.saved.source !== state.built) {
    state.saved = null;
    showSaveInfo('');
  }
  state.payload = state.saved?.tracked ? state.saved.data : state.built;
  el.payloadView.textContent = state.payload || '—';
  updateSaveCard();

  if (!state.payload) {
    setReady(false);
    setStatus('Preencha os campos obrigatórios para gerar o QR Code.');
    return false;
  }

  try {
    const opts = buildQrOptions(PREVIEW_SIZE, 'svg');
    if (!previewQr) {
      previewQr = new QRCodeStyling(opts);
      previewQr.append(el.preview);
    } else {
      previewQr.update(opts);
    }
    setReady(true);
    setStatus(
      state.saved?.tracked
        ? 'QR Code rastreado pronto · aponta para o seu link curto'
        : `QR Code pronto · ${state.payload.length} caracteres`,
      'ok'
    );
    return true;
  } catch (err) {
    console.error(err);
    setReady(false);
    setStatus('Conteúdo longo demais para um QR Code. Reduza o texto ou o nível de correção.', 'error');
    return false;
  }
}

const scheduleUpdate = debounce(update, 150);

async function download(extension) {
  if (!update()) return;
  const btn = extension === 'png' ? el.btnPng : el.btnSvg;
  btn.disabled = true;
  try {
    const size = state.style.exportSize;
    const qr = new QRCodeStyling(buildQrOptions(size, extension === 'png' ? 'canvas' : 'svg'));
    await qr.download({ name: state.saved?.tracked ? 'qrcode-rastreado' : `qrcode-${state.typeId}`, extension });
    setStatus(`Download ${extension.toUpperCase()} iniciado (${size}×${size} px).`, 'ok');
  } catch (err) {
    console.error(err);
    setStatus('Não foi possível gerar o arquivo. Tente novamente.', 'error');
  } finally {
    btn.disabled = false;
  }
}

/* ------------------------------------------------------------------ conta (opcional) */

const isHttpUrl = (v) => /^https?:\/\/[^\s]+$/i.test(v);

function showSaveInfo(html, kind = 'ok') {
  el.saveInfo.hidden = !html;
  el.saveInfo.dataset.kind = kind;
  el.saveInfo.innerHTML = html;
}

/** Ajusta o cartão "Salvar no meu painel" ao conteúdo atual. */
function updateSaveCard() {
  if (!state.account?.authenticated) return;
  const canTrackUrl = isHttpUrl(state.built);
  const canTrack = canTrackUrl && state.account.canTrack;
  el.saveTrack.disabled = !canTrack;
  el.trackWrap.classList.toggle('is-disabled', !canTrack);
  const track = canTrack && el.saveTrack.checked;
  el.btnSave.textContent = state.saved ? 'Salvo no painel ✓' : track ? 'Salvar e rastrear' : 'Salvar no painel';
  el.btnSave.disabled = !state.built || Boolean(state.saved);
  if (!canTrackUrl) {
    el.trackHint.textContent = 'Rastreamento disponível para conteúdos que são links (http/https). Este QR Code será salvo como estático.';
  } else if (!state.account.canTrack) {
    el.trackHint.textContent = 'Confirme seu e-mail (veja o link no seu painel) para liberar os links rastreados.';
  } else if (track) {
    el.trackHint.textContent = 'O QR Code apontará para um link curto que conta cada abertura e redireciona para o seu destino. Você poderá trocar o destino depois.';
  } else {
    el.trackHint.textContent = 'Sem rastreio: o link vai direto dentro do QR Code (não dá para contar aberturas).';
  }
}

async function saveToPanel(e) {
  e.preventDefault();
  if (!update() || state.saved) return;
  const track = !el.saveTrack.disabled && el.saveTrack.checked;
  el.btnSave.disabled = true;
  el.btnSave.textContent = 'Salvando...';
  try {
    const res = await fetch('api/qrcodes', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.account.csrf },
      body: JSON.stringify({
        type: state.typeId,
        content: state.built,
        title: el.saveTitle.value.trim(),
        track,
        style: state.style,
      }),
    });
    const json = await res.json().catch(() => ({}));
    if (res.status === 401 || res.status === 419) {
      showSaveInfo('Sua sessão expirou. <a href="entrar">Entre novamente</a> para salvar.', 'error');
      return;
    }
    if (!res.ok) throw new Error(json.error || 'Não foi possível salvar.');

    state.saved = { source: state.built, data: json.data, tracked: json.tracked, panelUrl: json.panelUrl };
    el.saveTitle.value = '';
    update();
    const link = document.createElement('a');
    link.href = json.panelUrl;
    link.textContent = 'Ver no painel';
    const msg = json.tracked
      ? `Salvo e rastreando! O QR Code agora aponta para <code></code>. Baixe o PNG/SVG abaixo. `
      : 'Salvo no seu painel. ';
    showSaveInfo(msg);
    if (json.tracked) el.saveInfo.querySelector('code').textContent = json.data;
    el.saveInfo.append(link);
    if (state.logo) el.saveInfo.append(' (o logo não fica salvo no painel: baixe agora a versão com logo).');
  } catch (err) {
    showSaveInfo('', 'error');
    el.saveInfo.hidden = false;
    el.saveInfo.textContent = err.message;
  } finally {
    updateSaveCard();
  }
}

/** Descobre se há alguém logado e adapta o cabeçalho e o cartão de conta. */
async function initAccount() {
  try {
    const res = await fetch('api/me', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error('sem backend');
    state.account = await res.json();
  } catch {
    // Sem backend PHP (ex.: servidor estático): só o gerador, sem funções de conta
    el.promoCard.hidden = true;
    document.querySelectorAll('[data-auth="guest"], a[href="#vantagens"]').forEach((n) => (n.hidden = true));
    return;
  }
  const a = state.account;
  if (!a.authenticated) {
    if (a.registration === false) {
      document.querySelectorAll('a[href="cadastrar"]').forEach((l) => (l.hidden = true));
    }
    return;
  }
  document.querySelectorAll('[data-auth="guest"]').forEach((n) => (n.hidden = true));
  document.querySelectorAll('[data-auth="user"]').forEach((n) => (n.hidden = false));
  if (a.isAdmin) document.querySelectorAll('[data-auth="admin"]').forEach((n) => (n.hidden = false));
  $('#account-name').textContent = a.name;
  $('#account-initial').textContent = (a.name || '?').trim().charAt(0).toUpperCase();
  el.promoCard.hidden = true;
  el.saveCard.hidden = false;
  el.saveCard.addEventListener('submit', saveToPanel);
  el.saveTrack.addEventListener('change', updateSaveCard);
  updateSaveCard();
}

/* ------------------------------------------------------------------ menu mobile */

function initNav() {
  const toggle = $('.nav-toggle');
  const nav = $('#menu-principal');
  toggle.addEventListener('click', () => {
    const open = toggle.getAttribute('aria-expanded') !== 'true';
    toggle.setAttribute('aria-expanded', open);
    nav.classList.toggle('is-open', open);
  });
  nav.addEventListener('click', (e) => {
    if (e.target.closest('a')) {
      toggle.setAttribute('aria-expanded', 'false');
      nav.classList.remove('is-open');
    }
  });
}

/* ------------------------------------------------------------------ init */

function init() {
  if (!QRCodeStyling) {
    setStatus('Falha ao carregar a biblioteca de QR Code (assets/vendor).', 'error');
    return;
  }

  const fromHash = location.hash.slice(1);
  if (QR_TYPES.some((t) => t.id === fromHash)) state.typeId = fromHash;

  renderTypeCards();
  renderShapeOptions();
  renderContentForm();
  syncStyleForm();
  initNav();

  el.contentForm.addEventListener('input', onContentInput);
  el.contentForm.addEventListener('change', onContentInput);
  el.contentForm.addEventListener('submit', (e) => {
    e.preventDefault();
    update();
  });
  el.styleForm.addEventListener('input', onStyleInput);
  el.styleForm.addEventListener('change', onStyleInput);
  el.styleForm.addEventListener('submit', (e) => e.preventDefault());
  el.logoFile.addEventListener('change', onLogoChange);
  el.logoClear.addEventListener('click', clearLogo);

  $('#resetStyle').addEventListener('click', () => {
    state.style = { ...DEFAULT_STYLE };
    syncStyleForm();
    update();
  });

  el.btnGenerate.addEventListener('click', () => {
    const ok = update();
    if (!ok) {
      const firstEmpty = [...el.contentForm.querySelectorAll('[required]')].find((i) => !i.value.trim());
      firstEmpty?.focus();
    } else if (window.matchMedia('(max-width: 960px)').matches) {
      $('.preview-card').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });
  el.btnPng.addEventListener('click', () => download('png'));
  el.btnSvg.addEventListener('click', () => download('svg'));

  update();
  initAccount();
}

init();
