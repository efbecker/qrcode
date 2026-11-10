/**
 * icons.js
 * Ícones SVG inline (traço 24x24) usados nos cards de tipo de conteúdo.
 * Desenhados para o projeto; sem dependências externas.
 */

const svg = (body) =>
  `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${body}</svg>`;

export const ICONS = {
  link: svg('<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3.2-3.2a4.5 4.5 0 0 0-6.4-6.4l-1.2 1.2"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3.2 3.2a4.5 4.5 0 0 0 6.4 6.4l1.2-1.2"/>'),
  text: svg('<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/>'),
  location: svg('<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>'),
  phone: svg('<path d="M21 16.5v3a2 2 0 0 1-2.2 2A19.8 19.8 0 0 1 2.5 5.2 2 2 0 0 1 4.5 3h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.4 10.9a16 16 0 0 0 4.7 4.7l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>'),
  email: svg('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>'),
  sms: svg('<path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 9h8M8 13h5"/>'),
  whatsapp: svg('<path d="M3.5 20.5l1.3-4.2A8.5 8.5 0 1 1 8 19.4z"/><path d="M9 8.5c0 3.5 3 6.5 6.5 6.5l1-1.5-2-1-1 .8a5 5 0 0 1-2.8-2.8l.8-1-1-2z"/>'),
  wifi: svg('<path d="M2 8.8a15 15 0 0 1 20 0"/><path d="M5 12.4a10.5 10.5 0 0 1 14 0"/><path d="M8.5 15.9a5.5 5.5 0 0 1 7 0"/><circle cx="12" cy="19.5" r="1" fill="currentColor"/>'),
  vcard: svg('<rect x="2" y="4" width="20" height="16" rx="2"/><circle cx="8.5" cy="10.5" r="2.2"/><path d="M5 16.5a3.6 3.6 0 0 1 7 0M14.5 9h4M14.5 13h4"/>'),
  pix: svg('<path d="M12 2.8l3.6 3.6-3.6 3.6-3.6-3.6zM12 14l3.6 3.6-3.6 3.6-3.6-3.6zM2.8 12l3.6-3.6 3.6 3.6-3.6 3.6zM14 12l3.6-3.6 3.6 3.6-3.6 3.6z"/>'),
  bitcoin: svg('<circle cx="12" cy="12" r="9.5"/><path d="M9.5 7.5h3.8a2 2 0 0 1 0 4H9.5zM9.5 11.5h4.3a2.2 2.2 0 0 1 0 4.5H9.5zM9.5 7.5V16.5M11 6v1.5M11 16v1.5"/>'),
  paypal: svg('<path d="M7 20l2.6-15h5.6a4 4 0 0 1 0 8H11l-1.2 7z"/><path d="M10 17l1.6-9.5h3.6a2.4 2.4 0 0 1 0 4.8"/>'),
};
