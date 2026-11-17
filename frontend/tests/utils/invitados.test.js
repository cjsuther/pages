import { describe, it, expect } from 'vitest';
import {
  estadoDeInvitado,
  etiquetaDeLugares,
  mensajeDeInvitacion,
  whatsAppDeInvitado,
} from '../../src/utils/invitados';

const INVITADO = {
  nombre: 'Ana Gómez',
  telefono: '11 2233-4455',
  url: 'https://rezon.ar/entrada/ABC123DEF456',
  cantidad: 2,
  ingresadas: 0,
  estado: 'vigente',
};

describe('invitados', () => {
  it('nombra los lugares como se dice: solo, +1, +2', () => {
    expect(etiquetaDeLugares(1)).toBe('Solo');
    expect(etiquetaDeLugares(2)).toBe('+1');
    expect(etiquetaDeLugares(3)).toBe('+2');
  });

  it('el mensaje lleva el link de la entrada y el evento', () => {
    const texto = mensajeDeInvitacion({ nombre: 'Ana Gómez', evento: 'La Noche', url: INVITADO.url });

    expect(texto).toContain('¡Hola Ana!');
    expect(texto).toContain('para La Noche');
    expect(texto).toContain(INVITADO.url);
  });

  it('con teléfono, el WhatsApp va directo a esa persona', () => {
    const url = whatsAppDeInvitado(INVITADO, 'La Noche');

    expect(url).toMatch(/^https:\/\/wa\.me\/5491122334455\?text=/);
    expect(decodeURIComponent(url)).toContain(INVITADO.url);
  });

  it('sin teléfono, abre WhatsApp para elegir a quién mandárselo', () => {
    const url = whatsAppDeInvitado({ ...INVITADO, telefono: '' }, 'La Noche');

    expect(url).toMatch(/^https:\/\/wa\.me\/\?text=/);
  });

  it('el estado dice si entró, si entró a medias o si se anuló', () => {
    expect(estadoDeInvitado(INVITADO).texto).toBe('Invitado');
    expect(estadoDeInvitado({ ...INVITADO, ingresadas: 1 }).texto).toBe('Entraron 1/2');
    expect(estadoDeInvitado({ ...INVITADO, ingresadas: 2 }).texto).toBe('Entró');
    expect(estadoDeInvitado({ ...INVITADO, estado: 'anulado' }).texto).toBe('Anulado');
  });
});
