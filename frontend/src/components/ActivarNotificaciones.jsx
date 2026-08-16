import React, { useState, useEffect, useContext, useCallback } from 'react';
import { Bell, BellOff, Share, PlusSquare, Smartphone, BatteryWarning, Check } from 'lucide-react';
import { AuthContext } from '../App';
import { detectarEntorno, diagnosticar, guiaDeBateria, PASOS } from '../utils/pwa';
import { activarNotificaciones, desactivarNotificaciones, estaSuscrito } from '../utils/pushNotifications';

/**
 * Activación de notificaciones push, con la guía de instalación que
 * corresponda a cada dispositivo.
 *
 * La instalación en iOS no es un detalle técnico sino una pantalla de
 * producto: es donde se pierde la mayoría de los usuarios
 * (GUIA-PUSH-PWA.md §11). Por eso el componente no se limita a decir "no se
 * puede": explica exactamente qué tocar.
 */
function ActivarNotificaciones({ compacto = false }) {
  const { token, apiUrl } = useContext(AuthContext);

  const [entorno, setEntorno] = useState(() => detectarEntorno());
  const [suscrito, setSuscrito] = useState(false);
  const [cargando, setCargando] = useState(false);
  const [mensaje, setMensaje] = useState(null);
  const [promptInstalar, setPromptInstalar] = useState(null);

  const diagnostico = diagnosticar(entorno);

  const refrescar = useCallback(async () => {
    setEntorno(detectarEntorno());
    setSuscrito(await estaSuscrito());
  }, []);

  useEffect(() => {
    refrescar();
  }, [refrescar]);

  // Android permite instalar con un toque; iOS no dispara nunca este evento.
  useEffect(() => {
    const alPoderInstalar = (e) => {
      e.preventDefault();
      setPromptInstalar(e);
    };

    const alInstalar = () => {
      setPromptInstalar(null);
      refrescar();
    };

    window.addEventListener('beforeinstallprompt', alPoderInstalar);
    window.addEventListener('appinstalled', alInstalar);

    return () => {
      window.removeEventListener('beforeinstallprompt', alPoderInstalar);
      window.removeEventListener('appinstalled', alInstalar);
    };
  }, [refrescar]);

  const instalar = async () => {
    if (!promptInstalar) return;

    promptInstalar.prompt();
    await promptInstalar.userChoice;
    setPromptInstalar(null);
    refrescar();
  };

  const activar = async () => {
    setCargando(true);
    setMensaje(null);

    try {
      const resultado = await activarNotificaciones(apiUrl, token);

      if (resultado.ok) {
        setSuscrito(true);
        setMensaje({ tipo: 'ok', texto: 'Listo, vas a recibir los eventos de las páginas que seguís.' });
      } else if (resultado.motivo === 'permiso') {
        setMensaje({
          tipo: 'error',
          texto:
            resultado.permiso === 'denied'
              ? 'Bloqueaste las notificaciones. Hay que habilitarlas desde los ajustes del teléfono.'
              : 'No se concedió el permiso.',
        });
      } else {
        setMensaje({ tipo: 'error', texto: 'No se pudo activar. Probá de nuevo en un momento.' });
      }
    } finally {
      setEntorno(detectarEntorno());
      setCargando(false);
    }
  };

  const desactivar = async () => {
    setCargando(true);
    setMensaje(null);

    const ok = await desactivarNotificaciones(apiUrl, token);

    setSuscrito(!ok);
    setMensaje(
      ok
        ? { tipo: 'ok', texto: 'Notificaciones desactivadas en este dispositivo.' }
        : { tipo: 'error', texto: 'No se pudo desactivar.' }
    );
    setCargando(false);
  };

  if (!token) {
    return null;
  }

  const guiaBateria = guiaDeBateria(entorno.marca);

  // ------------------------------------------------------------ ya activadas

  if (suscrito) {
    return (
      <div className="bg-gray-900 border border-gray-800 p-6 space-y-4">
        <div className="flex items-center gap-3">
          <Bell className="w-5 h-5 text-emerald-400 flex-shrink-0" />
          <div className="flex-1">
            <p className="font-bold text-white">Notificaciones activadas</p>
            <p className="text-sm text-gray-400">
              En este dispositivo{entorno.instalada ? ', con la app instalada' : ''}.
            </p>
          </div>
        </div>

        {mensaje && <Aviso mensaje={mensaje} />}

        {/* El ahorro de batería del fabricante demora o bloquea las
            notificaciones. Es del sistema operativo, no de la PWA. */}
        {entorno.esAndroid && guiaBateria && (
          <div className="flex gap-3 bg-black border border-gray-800 p-4">
            <BatteryWarning className="w-5 h-5 text-yellow-500 flex-shrink-0 mt-0.5" />
            <div className="text-sm">
              <p className="text-gray-300 font-medium mb-1">
                Tu {entorno.marca} puede demorar las notificaciones
              </p>
              <p className="text-gray-500">{guiaBateria}</p>
            </div>
          </div>
        )}

        <button
          onClick={desactivar}
          disabled={cargando}
          className="text-sm text-gray-500 hover:text-gray-300 transition disabled:opacity-50 flex items-center gap-2"
        >
          <BellOff className="w-4 h-4" />
          {cargando ? 'Desactivando...' : 'Desactivar en este dispositivo'}
        </button>
      </div>
    );
  }

  // -------------------------------------------------------- falta algún paso

  return (
    <div className={`bg-gray-900 border border-gray-800 ${compacto ? 'p-4' : 'p-6'} space-y-4`}>
      <div className="flex items-start gap-3">
        <IconoDelPaso paso={diagnostico.paso} />
        <div className="flex-1">
          <p className="font-bold text-white">{diagnostico.titulo}</p>
          <p className="text-sm text-gray-400 mt-1">{diagnostico.mensaje}</p>
        </div>
      </div>

      {diagnostico.instrucciones.length > 0 && (
        <ol className="space-y-2 pl-1">
          {diagnostico.instrucciones.map((instruccion, i) => (
            <li key={i} className="flex gap-3 text-sm text-gray-300">
              <span className="flex-shrink-0 w-5 h-5 rounded-full bg-gray-800 text-gray-400 text-xs flex items-center justify-center font-bold">
                {i + 1}
              </span>
              <span>{instruccion}</span>
            </li>
          ))}
        </ol>
      )}

      {/* iOS no expone ningún botón de instalar: la referencia visual al icono
          de Compartir es lo único que orienta al usuario. */}
      {diagnostico.paso === PASOS.INSTALAR && entorno.esIOS && (
        <div className="flex items-center gap-4 bg-black border border-gray-800 p-4 text-gray-400">
          <div className="flex flex-col items-center gap-1">
            <Share className="w-6 h-6" />
            <span className="text-[10px] uppercase tracking-wide">Compartir</span>
          </div>
          <span className="text-gray-700">→</span>
          <div className="flex flex-col items-center gap-1">
            <PlusSquare className="w-6 h-6" />
            <span className="text-[10px] uppercase tracking-wide text-center">Agregar a inicio</span>
          </div>
        </div>
      )}

      {mensaje && <Aviso mensaje={mensaje} />}

      <div className="flex flex-wrap gap-3">
        {promptInstalar && (
          <button
            onClick={instalar}
            className="bg-white text-black px-5 py-2.5 font-bold hover:bg-gray-200 transition flex items-center gap-2"
          >
            <Smartphone className="w-4 h-4" />
            Instalar aplicación
          </button>
        )}

        {diagnostico.puedeSuscribirse && (
          <button
            onClick={activar}
            disabled={cargando}
            className="bg-emerald-600 text-white px-5 py-2.5 font-bold hover:bg-emerald-500 transition disabled:opacity-50 flex items-center gap-2"
          >
            <Bell className="w-4 h-4" />
            {cargando ? 'Activando...' : 'Activar notificaciones'}
          </button>
        )}
      </div>
    </div>
  );
}

function IconoDelPaso({ paso }) {
  if (paso === PASOS.INSTALAR) {
    return <Smartphone className="w-5 h-5 text-white flex-shrink-0 mt-0.5" />;
  }

  if (paso === PASOS.PERMISO_DENEGADO || paso === PASOS.SOPORTE || paso === PASOS.NAVEGADOR) {
    return <BellOff className="w-5 h-5 text-yellow-500 flex-shrink-0 mt-0.5" />;
  }

  return <Bell className="w-5 h-5 text-emerald-400 flex-shrink-0 mt-0.5" />;
}

function Aviso({ mensaje }) {
  const esOk = mensaje.tipo === 'ok';

  return (
    <div
      className={`flex items-start gap-2 px-4 py-3 text-sm border ${
        esOk
          ? 'bg-emerald-950 border-emerald-800 text-emerald-200'
          : 'bg-red-950 border-red-800 text-red-200'
      }`}
    >
      {esOk && <Check className="w-4 h-4 flex-shrink-0 mt-0.5" />}
      <span>{mensaje.texto}</span>
    </div>
  );
}

export default ActivarNotificaciones;
