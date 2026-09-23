import { EncuestaForm, type Respuestas, type Seccion } from '@/components/pesv/encuesta-form';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Head, router, usePage } from '@inertiajs/react';
import { CheckCircle2, Loader2, TrafficCone } from 'lucide-react';
import { useState } from 'react';

interface Props {
    empresa: string | null;
    abierta: boolean;
    secciones: Seccion[];
    token: string;
    enviada: boolean;
}

/**
 * Encuesta de movilidad para el trabajador, sin iniciar sesión. Página suelta:
 * no usa el layout de la plataforma porque quien la abre no tiene cuenta.
 */
export default function EncuestaMovilidad({ empresa, abierta, secciones, token, enviada }: Props) {
    const errores = usePage().props.errors as Record<string, string>;
    const [valores, setValores] = useState<Respuestas>({});
    const [enviando, setEnviando] = useState(false);

    function enviar(e: React.FormEvent) {
        e.preventDefault();
        router.post(`/encuesta-movilidad/${token}`, { respuestas: valores } as never, {
            preserveScroll: 'errors',
            onStart: () => setEnviando(true),
            onFinish: () => setEnviando(false),
            onError: () => window.scrollTo({ top: 0, behavior: 'smooth' }),
        });
    }

    return (
        <div className="bg-muted/30 min-h-screen px-4 py-8">
            <Head title="Encuesta de movilidad" />
            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                <div className="flex items-center gap-3">
                    <div className="bg-primary text-primary-foreground flex size-11 items-center justify-center rounded-xl">
                        <TrafficCone className="size-6" />
                    </div>
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Encuesta de movilidad</h1>
                        <p className="text-muted-foreground text-sm">Plan Estratégico de Seguridad Vial{empresa ? ` · ${empresa}` : ''}</p>
                    </div>
                </div>

                {enviada ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 p-8 text-center">
                            <CheckCircle2 className="size-10 text-emerald-600" />
                            <p className="text-lg font-semibold">¡Gracias! Tu respuesta quedó registrada.</p>
                            <p className="text-muted-foreground text-sm">
                                Con ella la empresa identifica los riesgos viales de sus desplazamientos. Si te equivocaste, puedes volver a
                                responder: se guarda la última respuesta.
                            </p>
                        </CardContent>
                    </Card>
                ) : !abierta ? (
                    <Card>
                        <CardContent className="p-8 text-center">
                            <p className="font-semibold">Esta encuesta está cerrada.</p>
                            <p className="text-muted-foreground text-sm">Consulta con el responsable de seguridad vial de tu empresa.</p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardContent className="p-5 md:p-8">
                            <form onSubmit={enviar} className="space-y-6">
                                <p className="text-muted-foreground text-sm">
                                    Responde según tus desplazamientos habituales. Toma unos 10 minutos. Las preguntas con * son obligatorias.
                                </p>
                                {Object.keys(errores).length > 0 && (
                                    <p className="border-destructive/30 bg-destructive/10 text-destructive rounded-md border px-3 py-2 text-sm">
                                        Faltan respuestas obligatorias: están marcadas abajo.
                                    </p>
                                )}
                                <EncuestaForm secciones={secciones} valores={valores} onChange={setValores} errores={errores} />
                                <Button type="submit" size="lg" className="w-full gap-2" disabled={enviando}>
                                    {enviando && <Loader2 className="size-4 animate-spin" />} Enviar encuesta
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}
            </div>
        </div>
    );
}
