import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import Precios from '../../src/pages/Precios';
import { renderConProviders } from '../helpers/render';
import { mockFetch } from '../helpers/api';
import { calcularCargos } from '../../src/utils/cargos';

/**
 * La página de precios promete tres cosas: qué es gratis, cuánto se cobra por
 * entrada y cuándo llega la plata. Las dos últimas son números del servidor;
 * lo que se prueba es que se muestren tal cual y que, si no llegan, la página
 * siga diciendo lo mismo sin inventar ninguno.
 */
const COSTOS = { comision: 1.5, mercadopago: { porcentaje: 7.25, dias: 3 } };

function montar(costos = COSTOS) {
  const mock = mockFetch({ 'public/costos.php': costos });
  renderConProviders(<Precios />);
  return mock;
}

describe('Precios', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  describe('qué incluye', () => {
    it('lista la página y las herramientas de venta a $0', () => {
      montar();

      expect(screen.getByRole('heading', { name: 'Qué incluye' })).toBeInTheDocument();
      expect(screen.getByText(/Todo esto es gratis, para siempre/)).toBeInTheDocument();
      expect(screen.getByText('Avisos a tus seguidores')).toBeInTheDocument();
      expect(screen.getByText('Control en la puerta')).toBeInTheDocument();
      expect(screen.getByText('Reservas sin costo')).toBeInTheDocument();
    });

    it('lo agrupa por lo que le resuelve a quien organiza', () => {
      montar();

      for (const tema of ['Tu página y tus fechas', 'Llegar a tu público', 'Vender entradas', 'Vender productos', 'Trabajar en equipo']) {
        expect(screen.getByRole('heading', { name: tema })).toBeInTheDocument();
      }
    });

    it('cuenta lo nuevo: colaboraciones automáticas, recorte de imágenes y lo que faltaba', () => {
      montar();

      expect(screen.getByText(/las de confianza se aceptan solas/)).toBeInTheDocument();
      expect(screen.getByText(/Recortás cada imagen al subirla/)).toBeInTheDocument();
      expect(screen.getByText('Productos físicos')).toBeInTheDocument();
      expect(screen.getByText('Tipos de entrada y promos')).toBeInTheDocument();
      expect(screen.getByText('Cambios sin volver a cobrar')).toBeInTheDocument();
      expect(screen.getByText('Mails a tus clientes')).toBeInTheDocument();
      expect(screen.getByText(/tu propio pixel de Meta/)).toBeInTheDocument();
      expect(screen.getByText('Entrás con Google o Apple')).toBeInTheDocument();
      expect(screen.getByText('Carcajada, para el stand-up')).toBeInTheDocument();
    });
  });

  describe('quién paga la comisión', () => {
    // Las tasas de producción: con ellas el ejemplo tiene que dar lo mismo que
    // el checkout (utils/cargos.js) al centavo.
    const TASAS = { mp_porcentaje: 4.39, mp_fijo: 0, mp_iva: 21, plataforma_porcentaje: 1, plataforma_iva: 0 };
    const CON_CARGOS = { comision: 1, mercadopago: { porcentaje: 4.39, dias: 10 }, cargos: TASAS };

    it('ofrece las dos opciones con sus nombres', async () => {
      montar(CON_CARGOS);

      expect(await screen.findByText('La comisión la pago yo (organizador)')).toBeInTheDocument();
      expect(screen.getByText('La comisión la paga quien compra')).toBeInTheDocument();
    });

    it('si la paga quien compra, hace la cuenta del checkout con una entrada de $10.000', async () => {
      montar(CON_CARGOS);
      await screen.findByText('La comisión la paga quien compra');

      const { mercadopago, plataforma, total } = calcularCargos(10000, TASAS);
      expect([mercadopago, plataforma, total]).toEqual([566.6, 100, 10666.6]);

      expect(screen.getByText('+$100')).toBeInTheDocument();
      expect(screen.getByText('+≈ $566,60')).toBeInTheDocument();
      expect(screen.getByText('≈ $10.666,60')).toBeInTheDocument();
      expect(screen.getByText(/Vos recibís el precio completo: \$10\.000/)).toBeInTheDocument();
    });

    it('si la paga el organizador, muestra cuánto le queda', async () => {
      montar(CON_CARGOS);
      await screen.findByText('La comisión la pago yo (organizador)');

      // 10.000 − 100 de Rezonar − 531,19 de Mercado Pago (4,39% + 21% de IVA).
      expect(screen.getByText('−≈ $531,19')).toBeInTheDocument();
      expect(screen.getByText('≈ $9.368,81')).toBeInTheDocument();
    });

    it('aclara que lo de Mercado Pago depende del plazo de acreditación', async () => {
      montar(CON_CARGOS);

      expect(await screen.findByText(/depende del plazo de acreditación/)).toBeInTheDocument();
      expect(screen.getByText(/el ejemplo usa la de 10 días/)).toBeInTheDocument();
    });

    it('ya no dice que al comprador no se le suma nada', async () => {
      montar(CON_CARGOS);
      await screen.findByText('La comisión la paga quien compra');

      expect(screen.queryByText(/No le sumamos cargo por servicio/)).not.toBeInTheDocument();
      expect(screen.queryByText(/El comprador paga el precio que pusiste/)).not.toBeInTheDocument();
    });

    it('con la opción apagada en la plataforma no la ofrece', async () => {
      montar();

      await screen.findByText('1,5%');
      expect(screen.queryByText('La comisión la paga quien compra')).not.toBeInTheDocument();
      expect(screen.getByText(/El comprador paga el precio que pusiste/)).toBeInTheDocument();
    });

    it('sin Rezonar en el split no nombra nuestra comisión entre lo que se suma', async () => {
      montar({ ...CON_CARGOS, cargos: { ...TASAS, plataforma_porcentaje: 0 } });
      await screen.findByText('La comisión la paga quien compra');

      expect(screen.queryByText('+$100')).not.toBeInTheDocument();
    });
  });

  describe('la venta de entradas', () => {
    it('muestra la comisión como se escribe acá', async () => {
      montar();

      expect(await screen.findByText('1,5%')).toBeInTheDocument();
    });

    it('muestra lo que cobra Mercado Pago aparte', async () => {
      montar();

      expect(await screen.findByText(/Mercado Pago cobra 7,25%/)).toBeInTheDocument();
    });

    it('hace la cuenta con una entrada de ejemplo', async () => {
      montar();

      expect(await screen.findByText('$9.125')).toBeInTheDocument();
    });

    it('si el servidor no responde, habla de la comisión sin inventar un número', async () => {
      const { fetch } = mockFetch({ 'public/costos.php': { status: 500, body: {} } });
      renderConProviders(<Precios />);

      await waitFor(() => expect(fetch).toHaveBeenCalled());
      expect(screen.getByText(/Ves el porcentaje exacto antes de activar la venta/)).toBeInTheDocument();
      expect(screen.queryByText(/\d%/)).not.toBeInTheDocument();
      expect(screen.getByText(/por cada entrada o producto pago/)).toBeInTheDocument();
    });
  });

  describe('los productos digitales', () => {
    it('cobran la misma comisión que una entrada, y los gratis no pagan', async () => {
      montar();

      expect(await screen.findByText('por entrada o producto vendido, como comisión de Rezonar.')).toBeInTheDocument();
      expect(screen.getByText(/pagan la misma\s+comisión que una entrada/)).toBeInTheDocument();
      expect(screen.getByText(/los productos que publicás gratis no pagan comisión/)).toBeInTheDocument();
    });

    it('aparecen entre las herramientas de venta', () => {
      montar();

      expect(screen.getByText('Productos digitales')).toBeInTheDocument();
    });
  });

  describe('cuándo se cobra', () => {
    it('dice a cuántos días de la compra llega la plata', async () => {
      montar();

      expect(await screen.findByRole('heading', { name: /La plata te llega a los 3 días de cada compra\. No después del evento\./ }))
        .toBeInTheDocument();
    });

    it('lo contrasta con cobrar después del evento', () => {
      montar();

      expect(screen.getByText('En otras ticketeras')).toBeInTheDocument();
      expect(screen.getByText(/después de que termina el evento/)).toBeInTheDocument();
    });

    it('un día se dice en singular', async () => {
      montar({ comision: 1.5, mercadopago: { porcentaje: 7.25, dias: 1 } });

      expect(await screen.findByRole('heading', { name: /a los 1 día de cada compra/ })).toBeInTheDocument();
    });

    it('sin el plazo del servidor no inventa un número de días', async () => {
      montar({ comision: 1.5, mercadopago: null });

      await screen.findByText('1,5%');
      expect(screen.getByRole('heading', { name: /a los pocos días de cada compra/ })).toBeInTheDocument();
    });
  });
});
