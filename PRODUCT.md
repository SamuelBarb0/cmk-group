# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users
Three audiences, weighted equally (confirmed 2026-11-11):
- **CMK consultants** (consultor_admin / consultor_operativo): work several client companies a day; need to know which client to attend first and what is overdue there.
- **The client's SST/HSEQ lead** (cliente_admin / cliente_usuario): opens their own company daily or weekly to see what is due and what is failing, then records evidence in the modules.
- **The client's manager**: looks occasionally and wants a one-glance answer to "how is my company doing?".

## Product Purpose
CMK GROUP S.A.S. is a Colombian SST · HSEQ · PESV consultancy. This platform runs each client's integrated management system: SG-SST (Decreto 1072, Res. 0312), PESV (Res. 40595), ISO 9001/14001/45001, organised around a 20-module document map (M01–M20). Success: the client stays compliant and audit-ready, and the consultant spends time on what is late or failing instead of hunting for it.

## Positioning
Built by the consultancy around its own method: CMK's model documents, its M01–M20 map and the Colombian legal minimums (0312 indicators, PESV steps) are first-class, and the client's real data (readings, due dates, findings) drives what the platform flags.

## Operating Context
- Consultant view: consolidated across all active clients; selecting a client switches the whole app to that company.
- Client view: one company; modules contracted per client (some clients have only a subset).
- Recurring rituals: monthly indicator readings, annual 0312 self-assessment, work plan, management review, audits, document control.
- Desktop first (office work); must remain usable on a phone.

## Capabilities and Constraints
- Laravel + Inertia + React + Tailwind (shadcn/ui components); Spanish (Colombia) UI.
- Dashboard data already available: agenda of due dates, 0312 minimum indicators with 12-month series, compliance figures from the management report, alerts center, per-module summaries, per-client counts for the consultant.
- The dashboard must show only modules the client contracted and data the user is permitted to see.

## Brand Commitments
- Name: CMK GROUP (legal: CMK GROUP S.A.S.), tagline "SST · HSEQ · PESV".
- Established app identity in code: navy primary, warm off-white surfaces, Poppins for brand headings and Inter for UI (resources/css/app.css).

## Evidence on Hand
- Demo company "Empresa Demo S.A.S." with real-shaped data in the local database.
- No testimonials, client logos or published metrics; do not fabricate any.

## Product Principles
1. Show what needs action before what is fine.
2. One number, one place: a fact appears once on a screen, where it is acted on.
3. The client's legal minimums (0312, PESV) are the spine of every summary.
4. Respect the contract: never show a module the client did not hire.
