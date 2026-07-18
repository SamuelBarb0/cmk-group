import AppLogoIcon from './app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-white/95 p-0.5 shadow-sm">
                <AppLogoIcon className="size-6" />
            </div>
            <div className="ml-1 grid flex-1 text-left">
                <span className="font-brand mb-0.5 truncate text-sm leading-none font-bold tracking-tight">CMK GROUP</span>
                <span className="truncate text-[10px] leading-none opacity-70">SST · HSEQ · PESV</span>
            </div>
        </>
    );
}
