/**
 * payloads.js
 * Definição dos tipos de conteúdo (campos do formulário) e funções que
 * montam a string ("payload") que será codificada no QR Code.
 *
 * Cada tipo possui:
 *  - id, label, icon  → usados para desenhar o card de seleção
 *  - fields           → campos do formulário dinâmico
 *  - build(values)    → retorna a string final, ou "" se faltar dado obrigatório
 */

/* ---------------------------------------------------------------- utils */

const onlyDigits = (s = '') => String(s).replace(/\D+/g, '');

/** Remove acentos e caracteres fora do ASCII imprimível (exigência do Pix). */
const toAscii = (s = '') =>
  String(s)
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^\x20-\x7E]/g, '')
    .trim();

/** Escapa caracteres especiais do formato WIFI: (\ ; , : "). */
const escapeWifi = (s = '') => String(s).replace(/([\\;,:"])/g, '\\$1');

/** Escapa caracteres especiais do vCard (\ , ; e quebras de linha). */
const escapeVCard = (s = '') =>
  String(s).replace(/\\/g, '\\\\').replace(/\n/g, '\\n').replace(/([,;])/g, '\\$1');

/** Monta uma query string ignorando parâmetros vazios. */
const query = (params) => {
  const parts = Object.entries(params)
    .filter(([, v]) => v !== undefined && v !== null && String(v).trim() !== '')
    .map(([k, v]) => `${k}=${encodeURIComponent(String(v).trim())}`);
  return parts.length ? `?${parts.join('&')}` : '';
};

/** Adiciona https:// quando o usuário digita apenas "exemplo.com". */
const normalizeUrl = (url = '') => {
  const u = url.trim();
  if (!u) return '';
  if (/^[a-z][a-z0-9+.-]*:/i.test(u)) return u;
  return `https://${u}`;
};

/** Formata telefone internacional: mantém apenas dígitos e prefixa "+". */
const intlPhone = (s = '') => {
  const raw = String(s).trim();
  const digits = onlyDigits(raw);
  if (!digits) return '';
  return raw.startsWith('+') ? `+${digits}` : digits;
};

/* ---------------------------------------------------------------- Pix (BR Code / EMV-MPM) */

/** CRC16-CCITT (polinômio 0x1021, valor inicial 0xFFFF), exigido pelo BR Code. */
export function crc16(str) {
  let crc = 0xffff;
  for (let i = 0; i < str.length; i++) {
    crc ^= str.charCodeAt(i) << 8;
    for (let j = 0; j < 8; j++) {
      crc = crc & 0x8000 ? (crc << 1) ^ 0x1021 : crc << 1;
      crc &= 0xffff;
    }
  }
  return crc.toString(16).toUpperCase().padStart(4, '0');
}

/** Campo EMV: ID (2) + tamanho (2) + valor. */
const emv = (id, value) => `${id}${String(value.length).padStart(2, '0')}${value}`;

/** Normaliza a chave Pix: telefone vira +55..., CPF/CNPJ só dígitos, e-mail minúsculo. */
function normalizePixKey(key = '') {
  const k = key.trim();
  if (!k) return '';
  if (k.includes('@')) return k.toLowerCase();
  // Chave aleatória (EVP / UUID)
  if (/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(k)) return k.toLowerCase();
  const digits = onlyDigits(k);
  if (k.startsWith('+')) return `+${digits}`;
  // CPF (11) e CNPJ (14) são aceitos como dígitos puros
  if (/^\d{3}\.?\d{3}\.?\d{3}-?\d{2}$/.test(k) || /^\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}$/.test(k)) return digits;
  return k;
}

export function buildPix({ key, name, city, amount, txid, description }) {
  const pixKey = normalizePixKey(key);
  const merchantName = toAscii(name).slice(0, 25);
  const merchantCity = toAscii(city).slice(0, 15);
  if (!pixKey || !merchantName || !merchantCity) return '';

  const desc = toAscii(description);
  // Limite de 99 caracteres para o campo 26 inteiro
  const gui = emv('00', 'br.gov.bcb.pix') + emv('01', pixKey);
  const maxDesc = 99 - gui.length - 4;
  const account = gui + (desc && maxDesc > 0 ? emv('02', desc.slice(0, maxDesc)) : '');

  const value = parseFloat(String(amount || '').replace(',', '.'));
  const tx = toAscii(txid).replace(/[^A-Za-z0-9]/g, '').slice(0, 25) || '***';

  let payload =
    emv('00', '01') +
    emv('26', account) +
    emv('52', '0000') +
    emv('53', '986') +
    (value > 0 ? emv('54', value.toFixed(2)) : '') +
    emv('58', 'BR') +
    emv('59', merchantName) +
    emv('60', merchantCity) +
    emv('62', emv('05', tx)) +
    '6304';
  return payload + crc16(payload);
}

/* ---------------------------------------------------------------- tipos de conteúdo */

export const QR_TYPES = [
  {
    id: 'url',
    label: 'Link único',
    icon: 'link',
    fields: [
      { name: 'url', label: 'URL', type: 'url', placeholder: 'https://...', required: true, full: true },
    ],
    build: (v) => normalizeUrl(v.url),
  },
  {
    id: 'text',
    label: 'Texto',
    icon: 'text',
    fields: [
      { name: 'text', label: 'Texto', type: 'textarea', placeholder: 'Digite qualquer texto...', required: true, full: true, maxlength: 2000 },
    ],
    build: (v) => (v.text || '').trim() ? v.text : '',
  },
  {
    id: 'location',
    label: 'Localização',
    icon: 'location',
    fields: [
      { name: 'lat', label: 'Latitude', type: 'text', placeholder: '-23.550520', inputmode: 'decimal' },
      { name: 'lng', label: 'Longitude', type: 'text', placeholder: '-46.633308', inputmode: 'decimal' },
      {
        name: 'format', label: 'Formato', type: 'select', value: 'maps',
        options: [
          ['maps', 'Link do Google Maps (compatível com todos)'],
          ['geo', 'geo: (abre o app de mapas padrão)'],
        ],
      },
      { name: 'mapsUrl', label: 'Ou cole um link de mapa', type: 'url', placeholder: 'https://maps.app.goo.gl/...', full: true,
        hint: 'Se preenchido, o link tem prioridade sobre as coordenadas.' },
    ],
    build: (v) => {
      if ((v.mapsUrl || '').trim()) return normalizeUrl(v.mapsUrl);
      const lat = parseFloat(String(v.lat || '').replace(',', '.'));
      const lng = parseFloat(String(v.lng || '').replace(',', '.'));
      if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return '';
      return v.format === 'geo'
        ? `geo:${lat},${lng}`
        : `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;
    },
  },
  {
    id: 'call',
    label: 'Chamada',
    icon: 'phone',
    fields: [
      { name: 'phone', label: 'Telefone', type: 'tel', placeholder: '+55 11 91234-5678', required: true, full: true,
        hint: 'Inclua o código do país (ex.: +55) para funcionar em qualquer lugar.' },
    ],
    build: (v) => {
      const p = intlPhone(v.phone);
      return p ? `tel:${p}` : '';
    },
  },
  {
    id: 'email',
    label: 'E-mail',
    icon: 'email',
    fields: [
      { name: 'to', label: 'Destinatário', type: 'email', placeholder: 'contato@exemplo.com', required: true, full: true },
      { name: 'subject', label: 'Assunto', type: 'text', placeholder: 'Assunto do e-mail', full: true },
      { name: 'body', label: 'Mensagem', type: 'textarea', placeholder: 'Corpo da mensagem...', full: true },
    ],
    build: (v) => {
      const to = (v.to || '').trim();
      if (!to) return '';
      return `mailto:${to}${query({ subject: v.subject, body: v.body })}`;
    },
  },
  {
    id: 'sms',
    label: 'SMS',
    icon: 'sms',
    fields: [
      { name: 'phone', label: 'Número', type: 'tel', placeholder: '+55 11 91234-5678', required: true, full: true },
      { name: 'message', label: 'Mensagem', type: 'textarea', placeholder: 'Mensagem pré-definida...', full: true, maxlength: 160 },
    ],
    build: (v) => {
      const p = intlPhone(v.phone);
      if (!p) return '';
      return `SMSTO:${p}:${(v.message || '').trim()}`;
    },
  },
  {
    id: 'whatsapp',
    label: 'WhatsApp',
    icon: 'whatsapp',
    fields: [
      { name: 'ddi', label: 'DDI', type: 'tel', placeholder: '55', value: '55', inputmode: 'numeric', maxlength: 4 },
      { name: 'ddd', label: 'DDD', type: 'tel', placeholder: '11', inputmode: 'numeric', maxlength: 3 },
      { name: 'number', label: 'Número', type: 'tel', placeholder: '91234-5678', required: true, inputmode: 'numeric' },
      { name: 'message', label: 'Mensagem pré-definida', type: 'textarea', placeholder: 'Olá! Gostaria de mais informações.', full: true },
    ],
    build: (v) => {
      const number = onlyDigits(v.number);
      if (!number) return '';
      const full = onlyDigits(v.ddi) + onlyDigits(v.ddd) + number;
      return `https://wa.me/${full}${query({ text: v.message })}`;
    },
  },
  {
    id: 'wifi',
    label: 'Wi-Fi',
    icon: 'wifi',
    fields: [
      { name: 'ssid', label: 'Nome da rede (SSID)', type: 'text', placeholder: 'MinhaRede', required: true },
      {
        name: 'auth', label: 'Autenticação', type: 'select', value: 'WPA',
        options: [['WPA', 'WPA/WPA2/WPA3'], ['WEP', 'WEP'], ['nopass', 'Aberta (sem senha)']],
      },
      { name: 'password', label: 'Senha', type: 'text', placeholder: 'Senha da rede', showIf: (v) => v.auth !== 'nopass' },
      { name: 'hidden', label: 'Rede oculta', type: 'checkbox' },
    ],
    build: (v) => {
      const ssid = v.ssid || '';
      if (!ssid.trim()) return '';
      const auth = v.auth || 'WPA';
      let s = `WIFI:T:${auth};S:${escapeWifi(ssid)};`;
      if (auth !== 'nopass' && v.password) s += `P:${escapeWifi(v.password)};`;
      if (v.hidden) s += 'H:true;';
      return `${s};`;
    },
  },
  {
    id: 'vcard',
    label: 'V-Card simples',
    icon: 'vcard',
    fields: [
      { name: 'firstName', label: 'Nome', type: 'text', placeholder: 'Maria', required: true },
      { name: 'lastName', label: 'Sobrenome', type: 'text', placeholder: 'Silva' },
      { name: 'phone', label: 'Telefone', type: 'tel', placeholder: '+55 11 91234-5678' },
      { name: 'email', label: 'E-mail', type: 'email', placeholder: 'maria@empresa.com' },
      { name: 'company', label: 'Empresa', type: 'text', placeholder: 'Empresa Ltda.' },
      { name: 'title', label: 'Cargo', type: 'text', placeholder: 'Gerente de Projetos' },
      { name: 'website', label: 'Site', type: 'url', placeholder: 'https://empresa.com', full: true },
    ],
    build: (v) => {
      const first = (v.firstName || '').trim();
      const last = (v.lastName || '').trim();
      if (!first && !last) return '';
      const lines = [
        'BEGIN:VCARD',
        'VERSION:3.0',
        `N:${escapeVCard(last)};${escapeVCard(first)};;;`,
        `FN:${escapeVCard([first, last].filter(Boolean).join(' '))}`,
      ];
      if ((v.company || '').trim()) lines.push(`ORG:${escapeVCard(v.company.trim())}`);
      if ((v.title || '').trim()) lines.push(`TITLE:${escapeVCard(v.title.trim())}`);
      const tel = intlPhone(v.phone);
      if (tel) lines.push(`TEL;TYPE=CELL:${tel}`);
      if ((v.email || '').trim()) lines.push(`EMAIL;TYPE=INTERNET:${v.email.trim()}`);
      if ((v.website || '').trim()) lines.push(`URL:${normalizeUrl(v.website)}`);
      lines.push('END:VCARD');
      return lines.join('\n');
    },
  },
  {
    id: 'pix',
    label: 'Pix',
    icon: 'pix',
    fields: [
      { name: 'key', label: 'Chave Pix', type: 'text', placeholder: 'CPF, CNPJ, e-mail, +5511912345678 ou chave aleatória', required: true, full: true,
        hint: 'Chave do tipo telefone deve ter o formato +55DDDNÚMERO.' },
      { name: 'name', label: 'Nome do recebedor', type: 'text', placeholder: 'Maria Silva', required: true, maxlength: 25 },
      { name: 'city', label: 'Cidade', type: 'text', placeholder: 'São Paulo', required: true, maxlength: 15 },
      { name: 'amount', label: 'Valor (opcional)', type: 'text', placeholder: '0,00', inputmode: 'decimal' },
      { name: 'txid', label: 'Identificador (opcional)', type: 'text', placeholder: 'PEDIDO123', maxlength: 25 },
      { name: 'description', label: 'Descrição (opcional)', type: 'text', placeholder: 'Pagamento de serviço', full: true, maxlength: 40 },
    ],
    build: buildPix,
  },
  {
    id: 'bitcoin',
    label: 'Bitcoin',
    icon: 'bitcoin',
    fields: [
      { name: 'address', label: 'Endereço da carteira', type: 'text', placeholder: 'bc1q...', required: true, full: true },
      { name: 'amount', label: 'Quantia em BTC (opcional)', type: 'text', placeholder: '0.001', inputmode: 'decimal' },
      { name: 'label', label: 'Destinatário (opcional)', type: 'text', placeholder: 'Nome' },
      { name: 'message', label: 'Mensagem (opcional)', type: 'text', placeholder: 'Doação', full: true },
    ],
    build: (v) => {
      const addr = (v.address || '').trim();
      if (!addr) return '';
      const amount = String(v.amount || '').replace(',', '.').trim();
      return `bitcoin:${addr}${query({ amount: parseFloat(amount) > 0 ? amount : '', label: v.label, message: v.message })}`;
    },
  },
  {
    id: 'paypal',
    label: 'PayPal',
    icon: 'paypal',
    fields: [
      { name: 'user', label: 'Usuário PayPal.Me', type: 'text', placeholder: 'seuusuario', required: true,
        hint: 'O nome que aparece em paypal.me/seuusuario' },
      { name: 'amount', label: 'Valor (opcional)', type: 'text', placeholder: '10.00', inputmode: 'decimal' },
      {
        name: 'currency', label: 'Moeda', type: 'select', value: 'BRL',
        options: [['BRL', 'BRL (R$)'], ['USD', 'USD (US$)'], ['EUR', 'EUR (€)']],
      },
    ],
    build: (v) => {
      const user = (v.user || '').trim().replace(/^https?:\/\/(www\.)?paypal\.me\//i, '').replace(/\/.*$/, '');
      if (!user) return '';
      const amount = parseFloat(String(v.amount || '').replace(',', '.'));
      return `https://paypal.me/${encodeURIComponent(user)}${amount > 0 ? `/${amount.toFixed(2)}${v.currency || ''}` : ''}`;
    },
  },
];
