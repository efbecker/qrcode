/**
 * qr-options.js
 * Opções do qr-code-styling compartilhadas pelo gerador e pelo painel.
 */
export const PREVIEW_SIZE = 300;

export const DEFAULT_STYLE = Object.freeze({
  bgColor: '#ffffff',
  bgTransparent: false,
  dotsColor: '#000000',
  eyeFrameColor: '#000000',
  eyeCenterColor: '#000000',
  dotsType: 'square',
  eyeFrameType: 'square',
  eyeCenterType: 'square',
  ecLevel: 'Q',
  margin: 10,
  exportSize: 1024,
});

/** Monta as opções para um tamanho em pixels (margem proporcional ao preview de 300 px). */
export function qrOptions({ data, style = {}, size = PREVIEW_SIZE, type = 'svg', logo = null }) {
  const s = { ...DEFAULT_STYLE, ...style };
  const scale = size / PREVIEW_SIZE;
  return {
    width: size,
    height: size,
    type,
    data,
    margin: Math.round(s.margin * scale),
    qrOptions: { errorCorrectionLevel: s.ecLevel },
    image: logo || undefined,
    imageOptions: { hideBackgroundDots: true, imageSize: 0.35, margin: Math.round(4 * scale) },
    dotsOptions: { color: s.dotsColor, type: s.dotsType },
    backgroundOptions: { color: s.bgTransparent ? 'rgba(0,0,0,0)' : s.bgColor },
    cornersSquareOptions: { color: s.eyeFrameColor, type: s.eyeFrameType },
    cornersDotOptions: { color: s.eyeCenterColor, type: s.eyeCenterType },
  };
}
