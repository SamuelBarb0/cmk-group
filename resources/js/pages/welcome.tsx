import AppLogoIcon from '@/components/app-logo-icon';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, HardHat, Leaf, ShieldCheck, Truck } from 'lucide-react';

export default function Welcome() {
    const { auth, company } = usePage<SharedData>().props;
    const target = auth.user ? route('dashboard') : route('login');

    const pillars = [
        { icon: HardHat, title: 'SST', desc: 'Matriz de peligros, plan anual, indicadores y accidentes.' },
        { icon: Leaf, title: 'HSEQ', desc: 'Gestión ambiental, calidad y auditorías.' },
        { icon: Truck, title: 'PESV', desc: 'Gestión vial, conductores, vehículos y capacitaciones.' },
        { icon: ShieldCheck, title: 'IA aplicada', desc: 'Clasificación de incidentes, reportes y normativa colombiana.' },
    ];

    return (
        <>
            <Head title="CMK GROUP — Plataforma SST · HSEQ · PESV" />
            <div className="flex min-h-screen flex-col bg-[hsl(220,45%,13%)] text-white">
                {/* Barra superior */}
                <header className="mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-6">
                    <div className="flex items-center gap-3">
                        <div className="flex size-10 items-center justify-center rounded-lg bg-white/95 p-1">
                            <AppLogoIcon className="size-7" />
                        </div>
                        <div className="leading-tight">
                            <div className="font-brand text-lg font-bold tracking-tight">CMK GROUP</div>
                            <div className="text-[11px] text-white/60">SST · HSEQ · PESV</div>
                        </div>
                    </div>
                    <nav>
                        <Link
                            href={target}
                            className="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-[hsl(220,45%,13%)] transition hover:bg-white/90"
                        >
                            {auth.user ? 'Ir al panel' : 'Ingresar'}
                            <ArrowRight className="size-4" />
                        </Link>
                    </nav>
                </header>

                {/* Héroe */}
                <main className="mx-auto flex w-full max-w-6xl flex-1 flex-col justify-center px-6 py-12">
                    <div className="max-w-2xl">
                        <span className="inline-block rounded-full border border-white/20 px-3 py-1 text-xs font-medium text-white/70">
                            Plataforma de consultoría con Inteligencia Artificial
                        </span>
                        <h1 className="font-brand mt-5 text-4xl leading-tight font-bold tracking-tight sm:text-5xl">
                            Gestión SST, HSEQ y PESV, <span className="text-[hsl(213,55%,70%)]">multiplicada por IA</span>
                        </h1>
                        <p className="mt-4 text-lg text-white/70">
                            Centraliza la información de todos tus clientes, trabaja en campo desde el celular y genera reportes auditables en
                            minutos. Una plataforma a la medida de {company.name}.
                        </p>
                        <div className="mt-8 flex flex-wrap gap-3">
                            <Link
                                href={target}
                                className="inline-flex items-center gap-2 rounded-lg bg-white px-5 py-3 text-sm font-semibold text-[hsl(220,45%,13%)] transition hover:bg-white/90"
                            >
                                Comenzar <ArrowRight className="size-4" />
                            </Link>
                        </div>
                    </div>

                    {/* Pilares */}
                    <div className="mt-16 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {pillars.map((p) => (
                            <div key={p.title} className="rounded-xl border border-white/10 bg-white/5 p-5 backdrop-blur">
                                <div className="flex size-10 items-center justify-center rounded-lg bg-white/10">
                                    <p.icon className="size-5 text-[hsl(213,55%,72%)]" />
                                </div>
                                <div className="font-brand mt-4 font-semibold">{p.title}</div>
                                <p className="mt-1 text-sm text-white/60">{p.desc}</p>
                            </div>
                        ))}
                    </div>
                </main>

                <footer className="mx-auto w-full max-w-6xl px-6 py-8 text-xs text-white/40">
                    {company.legal_name} · NIT {company.nit} · {company.domain}
                </footer>
            </div>
        </>
    );
}
