import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import PanelMetricas, { variacion, fechaCorta, matriz } from '../../src/components/PanelMetricas';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch } from '../helpers/api';

const autenticado = () => crearAuth({ token: 'tok-123', user: usuarioDePrueba() });

const informe = (overrides = {}) => ({
  configurado: true,
  dias: 30,
  desde: '2026-08-12',
  hasta: '2026-09-10',
  resumen: {
    actual: { visitas: 300, personas: 210 },
    previo: { visitas: 200, personas: 150 },
  },
  por_dia: [
    { dia: '2026-09-08', visitas: 10, personas: 8 },
    { dia: '2026-09-09', visitas: 0, personas: 0 },
    { dia: '2026-09-10', visitas: 25, personas: 20 },
  ],
  origen: [
    { nombre: 'Organic Social', visitas: 180, personas: 140 },
    { nombre: 'Direct', visitas: 120, personas: 70 },
  ],
  dispositivo: [{ nombre: 'mobile', visitas: 240, personas: 180 }],
  ciudad: [{ nombre: 'Buenos Aires', visitas: 150, personas: 110 }],
  quien: {
    edad: [
      { nombre: '18-24', visitas: 60, personas: 40 },
      { nombre: '25-34', visitas: 90, personas: 60 },
    ],
    genero: [
      { nombre: 'Mujeres', visitas: 120, personas: 70 },
      { nombre: 'Varones', visitas: 30, personas: 30 },
    ],
    cruce: [
      { edad: '18-24', genero: 'Mujeres', visitas: 40, personas: 25 },
      { edad: '18-24', genero: 'Varones', visitas: 20, personas: 15 },
      { edad: '25-34', genero: 'Mujeres', visitas: 80, personas: 45 },
      { edad: '25-34', genero: 'Varones', visitas: 10, personas: 15 },
    ],
    hay_datos: true,
    retenido: false,
  },
  ...overrides,
});

const sinPublico = (overrides = {}) => ({
  edad: [], genero: [], cruce: [], hay_datos: false, retenido: true, ...overrides,
});

function montar({ datos = informe(), status = 200 } = {}) {
  const mock = mockFetch({
    'pages/metricas.php': status === 200 ? datos : { status, body: { error: 'Google dijo que no' } },
  });

  renderConProviders(<PanelMetricas pageId={3} slug="la-banda" />, { auth: autenticado() });

  return mock;
}

describe('PanelMetricas', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  describe('los números', () => {
    it('muestra visitas y personas', async () => {
      montar();

      expect(await screen.findByText('300')).toBeInTheDocument();
      expect(screen.getByText('210')).toBeInTheDocument();
    });

    /**
     * Un número solo no dice nada: 300 visitas puede ser el mejor mes o la
     * mitad del anterior. Lo que se mira es si sube o baja.
     */
    it('compara contra el período anterior', async () => {
      montar();

      expect(await screen.findByText('+50% vs. antes')).toBeInTheDocument();
    });

    it('avisa cuando bajó', async () => {
      montar({
        datos: informe({
          resumen: { actual: { visitas: 100, personas: 50 }, previo: { visitas: 200, personas: 50 } },
        }),
      });

      expect(await screen.findByText('-50% vs. antes')).toBeInTheDocument();
    });

    /** Comparar contra cero da un "+100%" verdadero que no significa nada. */
    it('sin período anterior no inventa una variación', async () => {
      montar({
        datos: informe({
          resumen: { actual: { visitas: 100, personas: 50 }, previo: { visitas: 0, personas: 0 } },
        }),
      });

      expect(await screen.findAllByText('Sin datos del período anterior')).toHaveLength(2);
    });
  });

  describe('las tablas', () => {
    it('muestra de dónde vienen, con qué y desde dónde', async () => {
      montar();

      expect(await screen.findByText('Organic Social')).toBeInTheDocument();
      expect(screen.getByText('mobile')).toBeInTheDocument();
      expect(screen.getByText('Buenos Aires')).toBeInTheDocument();
    });

    /** La parte sobre el total es lo que hace comparable una fila con otra. */
    it('dice qué parte del total es cada fila', async () => {
      montar();

      await screen.findByText('Organic Social');
      expect(screen.getByText('60%')).toBeInTheDocument();
      expect(screen.getByText('40%')).toBeInTheDocument();
    });

    it('sin datos lo dice en vez de mostrar una tabla vacía', async () => {
      montar({ datos: informe({ origen: [], dispositivo: [], ciudad: [] }) });

      expect(await screen.findAllByText('Todavía no hay visitas registradas.')).toHaveLength(3);
    });
  });

  describe('la ventana', () => {
    it('arranca en 30 días', async () => {
      const { llamadas } = montar();

      await screen.findByText('300');
      expect(llamadas[0].url).toContain('dias=30');
      expect(llamadas[0].url).toContain('page_id=3');
    });

    it('se puede cambiar', async () => {
      const { llamadas } = montar();
      await screen.findByText('300');

      fireEvent.click(screen.getByRole('button', { name: '7 días' }));

      await waitFor(() => {
        expect(llamadas.some((l) => l.url.includes('dias=7'))).toBe(true);
      });
    });
  });

  describe('cuando no hay nada que mostrar', () => {
    /**
     * Sin credenciales no se rompió nada: falta configurarlo, y eso se hace en
     * Google. Un error rojo le diría a quien administra una página que el sitio
     * anda mal, y no hay nada que pueda hacer al respecto.
     */
    it('sin conectar explica qué falta, sin parecer un error', async () => {
      montar({ datos: { configurado: false, motivo: 'Falta conectar Google Analytics en el servidor.' } });

      expect(await screen.findByText(/Todavía no hay números para mostrar/)).toBeInTheDocument();
      expect(screen.getByText(/Falta conectar Google Analytics/)).toBeInTheDocument();
    });

    it('un error de Google se muestra con su motivo', async () => {
      montar({ status: 502 });

      expect(await screen.findByText('Google dijo que no')).toBeInTheDocument();
    });
  });

  /**
   * Los números son un piso y no la verdad: Google tarda en procesar y a quien
   * bloquea publicidad no lo mide. Callarlo hace que alguien saque conclusiones
   * sobre un número que no es el que cree.
   */
  it('dice de dónde salen los datos y qué les falta', async () => {
    montar();

    await screen.findByText('300');
    expect(screen.getByText(/tardan hasta dos días en procesarse/)).toBeInTheDocument();
    expect(screen.getByText(/bloqueador de publicidad no se mide/)).toBeInTheDocument();
  });

  it('enlaza a la página que está midiendo', async () => {
    montar();

    expect(await screen.findByRole('link', { name: /rezon\.ar\/la-banda/ }))
      .toHaveAttribute('href', '/la-banda');
  });

  describe('quién te mira', () => {
    it('muestra edad y género', async () => {
      montar();

      expect(await screen.findByText('Por edad')).toBeInTheDocument();
      expect(screen.getByText('Por género')).toBeInTheDocument();
      // Dos veces: en la lista por edad y como fila del cruce.
      expect(screen.getAllByText('25-34')).toHaveLength(2);
      expect(screen.getAllByText('Mujeres').length).toBeGreaterThan(0);
    });

    /**
     * El cruce es lo que de verdad describe a un público: "25 a 34" y
     * "mujeres" por separado pueden ser dos grupos que casi no se tocan.
     */
    it('cruza las dos cosas', async () => {
      montar();

      expect(await screen.findByText('Edad y género juntos')).toBeInTheDocument();
      // 45 es el grupo más grande: mujeres de 25 a 34.
      expect(screen.getByText('45')).toBeInTheDocument();
    });

    /**
     * Una tabla vacía sin explicación se lee como "no te mira nadie", y lo que
     * pasa es que Google no lo clasifica.
     */
    it('sin datos explica por qué en vez de mostrar tablas vacías', async () => {
      montar({ datos: informe({ quien: sinPublico() }) });

      expect(await screen.findByText('Todavía no hay público clasificado')).toBeInTheDocument();
      expect(screen.queryByText('Edad y género juntos')).not.toBeInTheDocument();
    });

    /** Si Google escondió filas, lo que se ve es una parte y hay que decirlo. */
    it('avisa cuando Google escondió parte de las filas', async () => {
      montar({ datos: informe({ quien: { ...informe().quien, retenido: true } }) });

      expect(await screen.findByText(/Google escondió parte de las filas/)).toBeInTheDocument();
    });

    /** Un informe viejo, sin la sección, no tiene que romper la pantalla. */
    it('sin la sección no rompe', async () => {
      montar({ datos: informe({ quien: undefined }) });

      await screen.findByText('300');
      expect(screen.queryByText('Quién te mira')).not.toBeInTheDocument();
    });
  });

  describe('matriz', () => {
    it('arma las filas y las columnas de lo que vino', () => {
      const m = matriz([
        { edad: '18-24', genero: 'Mujeres', personas: 25 },
        { edad: '25-34', genero: 'Varones', personas: 15 },
      ]);

      expect(m.edades).toEqual(['18-24', '25-34']);
      expect(m.generos).toEqual(['Mujeres', 'Varones']);
      expect(m.valor('18-24', 'Mujeres')).toBe(25);
    });

    /** Un cruce que Google no devolvió es cero, no un hueco. */
    it('lo que no vino es cero', () => {
      const m = matriz([{ edad: '18-24', genero: 'Mujeres', personas: 25 }]);

      expect(m.valor('18-24', 'Varones')).toBe(0);
    });

    it('sin filas no hay matriz', () => {
      expect(matriz([]).edades).toEqual([]);
      expect(matriz().edades).toEqual([]);
    });
  });

  describe('variacion', () => {
    it('calcula el porcentaje', () => {
      expect(variacion(150, 100)).toBe(50);
      expect(variacion(50, 100)).toBe(-50);
      expect(variacion(100, 100)).toBe(0);
    });

    it('contra cero no hay variación', () => {
      expect(variacion(100, 0)).toBeNull();
    });
  });

  describe('fechaCorta', () => {
    it('deja día y mes', () => {
      expect(fechaCorta('2026-09-10')).toBe('10/09');
    });
  });
});
