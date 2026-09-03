import { Markdown } from '@/components/asistente/markdown';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Bot, Loader2, MessagesSquare, RotateCcw, Send, Sparkles, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface Mensaje {
    role: 'user' | 'assistant';
    texto: string;
    herramientas: string[];
}

/** Qué se le muestra al usuario mientras la IA usa cada herramienta. */
const ACCIONES: Record<string, string> = {
    contexto_organizacion: 'Consultando los datos de la empresa',
    listar_plantillas: 'Revisando el catálogo de plantillas',
    listar_documentos: 'Revisando los documentos existentes',
    leer_documento: 'Leyendo el documento',
    listar_empleados: 'Consultando los trabajadores',
    resumen_iperc: 'Consultando la matriz IPERC',
    resumen_indicadores: 'Consultando los indicadores',
    resumen_plan_trabajo: 'Consultando el plan de trabajo anual',
    resumen_diagnostico: 'Consultando el diagnóstico de estándares mínimos',
    generar_desde_plantilla: 'Generando el documento desde la plantilla',
    crear_documento: 'Creando el documento',
    actualizar_documento: 'Actualizando el documento',
};

/** Herramientas que modifican datos: tras usarlas conviene refrescar la página. */
const ESCRITURA = ['generar_desde_plantilla', 'crear_documento', 'actualizar_documento'];

const SUGERENCIAS = [
    'Genera la Política del SGI para este cliente',
    '¿Qué documentos le faltan a esta empresa?',
    'Redacta el informe de gestión con los indicadores del año',
];

/** Token CSRF que Laravel deja en la cookie XSRF-TOKEN. */
function csrfToken(): string {
    const cookie = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}

export function ChatWidget() {
    const { can } = usePermissions();
    const { tenant, modulos_contratados } = usePage<SharedData>().props;

    const [abierto, setAbierto] = useState(false);
    const [cargado, setCargado] = useState(false);
    const [mensajes, setMensajes] = useState<Mensaje[]>([]);
    const [borrador, setBorrador] = useState('');
    const [enviando, setEnviando] = useState(false);
    const [accion, setAccion] = useState<string | null>(null);
    const [pensando, setPensando] = useState('');
    const [error, setError] = useState<string | null>(null);

    const finRef = useRef<HTMLDivElement>(null);
    const cajaRef = useRef<HTMLTextAreaElement>(null);
    const abortRef = useRef<AbortController | null>(null);

    const disponible = can('documents.view') && (modulos_contratados === null || modulos_contratados.includes('documentos-ia'));

    // Autoscroll al final en cada trozo que llega.
    useEffect(() => {
        finRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [mensajes, accion, pensando]);

    // El historial se carga solo la primera vez que se abre el panel.
    useEffect(() => {
        if (!abierto || cargado) return;

        setCargado(true);

        fetch(route('asistente.history'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : { mensajes: [] }))
            .then((d) => setMensajes(d.mensajes ?? []))
            .catch(() => setMensajes([]));
    }, [abierto, cargado]);

    // Al cambiar de cliente el hilo es otro: se descarta lo que hubiera en pantalla.
    useEffect(() => {
        setMensajes([]);
        setCargado(false);
    }, [tenant?.id]);

    useEffect(() => {
        if (abierto) cajaRef.current?.focus();
    }, [abierto]);

    const enviar = useCallback(async () => {
        const texto = borrador.trim();
        if (texto === '' || enviando) return;

        setBorrador('');
        setError(null);
        setEnviando(true);
        setMensajes((prev) => [...prev, { role: 'user', texto, herramientas: [] }, { role: 'assistant', texto: '', herramientas: [] }]);

        const usadas: string[] = [];
        const controller = new AbortController();
        abortRef.current = controller;

        /** Añade texto al mensaje del asistente que se está escribiendo. */
        const anexar = (delta: string) =>
            setMensajes((prev) => {
                const copia = [...prev];
                const ultimo = copia[copia.length - 1];
                copia[copia.length - 1] = { ...ultimo, texto: ultimo.texto + delta };
                return copia;
            });

        try {
            const respuesta = await fetch(route('asistente.stream'), {
                method: 'POST',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/event-stream',
                    'X-XSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ mensaje: texto }),
            });

            if (!respuesta.ok || !respuesta.body) {
                throw new Error(respuesta.status === 419 ? 'La sesión expiró. Recarga la página.' : `El servidor respondió ${respuesta.status}.`);
            }

            const lector = respuesta.body.getReader();
            const decodificador = new TextDecoder();
            let buffer = '';

            for (;;) {
                const { done, value } = await lector.read();
                if (done) break;

                buffer += decodificador.decode(value, { stream: true });

                // Los eventos SSE se separan por una línea en blanco.
                let corte: number;
                while ((corte = buffer.indexOf('\n\n')) !== -1) {
                    const trama = buffer.slice(0, corte);
                    buffer = buffer.slice(corte + 2);

                    let evento = '';
                    let datos = '';

                    for (const linea of trama.split('\n')) {
                        if (linea.startsWith('event:')) evento = linea.slice(6).trim();
                        else if (linea.startsWith('data:')) datos += linea.slice(5).trim();
                    }

                    if (evento === '' || datos === '') continue;

                    const carga = JSON.parse(datos);

                    if (evento === 'texto') {
                        setAccion(null);
                        setPensando('');
                        anexar(carga.delta);
                    } else if (evento === 'turno') {
                        // Turno nuevo del asistente: burbuja nueva, igual que
                        // se verá luego al releer el historial.
                        setMensajes((prev) => [...prev, { role: 'assistant', texto: '', herramientas: [] }]);
                    } else if (evento === 'pensando') {
                        setPensando((p) => (p + carga.delta).slice(-160));
                    } else if (evento === 'herramienta') {
                        if (carga.estado === 'inicio') {
                            setPensando('');
                            setAccion(ACCIONES[carga.nombre] ?? carga.nombre);
                            usadas.push(carga.nombre);
                            setMensajes((prev) => {
                                const copia = [...prev];
                                const ultimo = copia[copia.length - 1];
                                copia[copia.length - 1] = { ...ultimo, herramientas: [...ultimo.herramientas, carga.nombre] };
                                return copia;
                            });
                        } else {
                            setAccion(null);
                        }
                    } else if (evento === 'aviso') {
                        anexar(`\n\n_${carga.mensaje}_`);
                    } else if (evento === 'error') {
                        setError(carga.mensaje);
                    }
                }
            }
        } catch (e) {
            if ((e as Error).name !== 'AbortError') {
                setError((e as Error).message || 'No se pudo contactar al asistente.');
            }
        } finally {
            setEnviando(false);
            setAccion(null);
            setPensando('');
            abortRef.current = null;

            // Si la IA tocó documentos, la pantalla de atrás quedó desactualizada.
            if (usadas.some((u) => ESCRITURA.includes(u))) {
                router.reload();
            }
        }
    }, [borrador, enviando]);

    const limpiar = useCallback(() => {
        abortRef.current?.abort();

        fetch(route('asistente.clear'), {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'X-XSRF-TOKEN': csrfToken(), Accept: 'application/json' },
        }).finally(() => {
            setMensajes([]);
            setError(null);
            setEnviando(false);
        });
    }, []);

    if (!disponible) return null;

    return (
        <>
            {/* Lanzador */}
            <button
                type="button"
                onClick={() => setAbierto((a) => !a)}
                aria-label={abierto ? 'Cerrar asistente' : 'Abrir asistente'}
                className={cn(
                    'fixed right-5 bottom-5 z-50 flex size-13 items-center justify-center rounded-full shadow-lg transition',
                    'bg-[#16243F] text-white hover:bg-[#0F1B30] focus-visible:ring-2 focus-visible:ring-[#16243F] focus-visible:ring-offset-2 focus-visible:outline-none',
                    abierto && 'scale-90 opacity-0',
                )}
            >
                <MessagesSquare className="size-6" />
            </button>

            {/* Panel */}
            <div
                className={cn(
                    'fixed z-50 flex flex-col overflow-hidden border border-border bg-background shadow-2xl transition-all duration-200',
                    'inset-x-0 bottom-0 h-[85dvh] rounded-t-2xl',
                    'sm:inset-x-auto sm:right-5 sm:bottom-5 sm:h-[min(46rem,80dvh)] sm:w-[26rem] sm:rounded-2xl',
                    abierto ? 'pointer-events-auto translate-y-0 opacity-100' : 'pointer-events-none translate-y-4 opacity-0',
                )}
            >
                <header className="flex items-center gap-2 border-b border-border bg-[#16243F] px-4 py-3 text-white">
                    <Sparkles className="size-4 shrink-0" />
                    <div className="min-w-0 flex-1">
                        <p className="text-sm leading-tight font-semibold">Asistente CMK</p>
                        <p className="truncate text-[11px] text-white/70">{tenant ? tenant.name : 'Sin cliente seleccionado'}</p>
                    </div>
                    <button type="button" onClick={limpiar} title="Empezar de nuevo" className="rounded p-1.5 text-white/70 hover:bg-white/10 hover:text-white">
                        <RotateCcw className="size-4" />
                    </button>
                    <button type="button" onClick={() => setAbierto(false)} title="Cerrar" className="rounded p-1.5 text-white/70 hover:bg-white/10 hover:text-white">
                        <X className="size-4" />
                    </button>
                </header>

                <div className="flex-1 space-y-3 overflow-y-auto px-4 py-4">
                    {mensajes.length === 0 && (
                        <div className="space-y-3 py-6 text-center">
                            <Bot className="mx-auto size-9 text-muted-foreground" />
                            <p className="text-sm font-medium">Redactamos documentos del SGI</p>
                            <p className="px-2 text-xs text-muted-foreground">
                                {tenant
                                    ? `Consulto los datos reales de ${tenant.name} y genero el documento como borrador.`
                                    : 'Selecciona un cliente arriba para poder consultar sus datos y generar documentos.'}
                            </p>
                            <div className="space-y-1.5 pt-1">
                                {SUGERENCIAS.map((s) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setBorrador(s)}
                                        className="w-full rounded-lg border border-border px-3 py-2 text-left text-xs text-muted-foreground transition hover:bg-accent hover:text-foreground"
                                    >
                                        {s}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    {mensajes.map((m, i) =>
                        m.role === 'user' ? (
                            <div key={i} className="flex justify-end">
                                <div className="max-w-[85%] rounded-2xl rounded-br-sm bg-[#16243F] px-3 py-2 text-sm whitespace-pre-wrap text-white">{m.texto}</div>
                            </div>
                        ) : (
                            <div key={i} className="space-y-1.5">
                                {m.herramientas.length > 0 && (
                                    <div className="flex flex-wrap gap-1">
                                        {[...new Set(m.herramientas)].map((h) => (
                                            <span key={h} className="rounded-full bg-muted px-2 py-0.5 text-[10px] text-muted-foreground">
                                                {ACCIONES[h] ?? h}
                                            </span>
                                        ))}
                                    </div>
                                )}
                                {m.texto !== '' && (
                                    <div className="max-w-full rounded-2xl rounded-bl-sm bg-muted/50 px-3 py-2">
                                        <Markdown>{m.texto}</Markdown>
                                    </div>
                                )}
                            </div>
                        ),
                    )}

                    {(accion || (enviando && pensando)) && (
                        <div className="flex items-start gap-2 px-1 text-xs text-muted-foreground">
                            <Loader2 className="mt-0.5 size-3.5 shrink-0 animate-spin" />
                            <span className="italic">{accion ? `${accion}…` : pensando}</span>
                        </div>
                    )}

                    {enviando && !accion && !pensando && (
                        <div className="flex items-center gap-2 px-1 text-xs text-muted-foreground">
                            <Loader2 className="size-3.5 animate-spin" />
                            <span>Pensando…</span>
                        </div>
                    )}

                    {error && <p className="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">{error}</p>}

                    <div ref={finRef} />
                </div>

                <div className="border-t border-border p-3">
                    <div className="flex items-end gap-2">
                        <textarea
                            ref={cajaRef}
                            value={borrador}
                            onChange={(e) => setBorrador(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' && !e.shiftKey) {
                                    e.preventDefault();
                                    void enviar();
                                }
                            }}
                            rows={1}
                            placeholder={tenant ? 'Pide un documento o pregunta algo…' : 'Selecciona un cliente para empezar…'}
                            className="max-h-32 min-h-9 flex-1 resize-none rounded-lg border border-input bg-transparent px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        />
                        <Button type="button" size="icon" onClick={() => void enviar()} disabled={enviando || borrador.trim() === ''} className="size-9 shrink-0">
                            {enviando ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                        </Button>
                    </div>
                    <p className="mt-1.5 text-[10px] text-muted-foreground">Los documentos que genere quedan como borrador para tu revisión.</p>
                </div>
            </div>
        </>
    );
}
