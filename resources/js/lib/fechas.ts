/**
 * La fecha de hoy en la zona del navegador, como `AAAA-MM-DD` (el formato de
 * los `<input type="date">`).
 *
 * NO usar `new Date().toISOString().slice(0, 10)`: esa es la fecha en UTC, y en
 * Colombia (UTC−5) desde las 7 p. m. ya es el día siguiente, así que un registro
 * hecho de noche quedaba fechado mañana.
 */
export function hoy(): string {
    const d = new Date();
    const dd = (n: number) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${dd(d.getMonth() + 1)}-${dd(d.getDate())}`;
}
