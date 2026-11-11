---
name: CMK GROUP
description: SST · HSEQ · PESV management system for CMK's consultants and their client companies
colors:
  primary: "hsl(220, 48%, 17%)"
  primary-foreground: "hsl(40, 30%, 98%)"
  brand-navy: "#16243f"
  hero-navy-dark: "hsl(220, 42%, 13%)"
  ring: "hsl(220, 48%, 30%)"
  background: "hsl(40, 20%, 98.5%)"
  foreground: "hsl(220, 40%, 12%)"
  card: "hsl(0, 0%, 100%)"
  muted: "hsl(220, 16%, 95%)"
  muted-foreground: "hsl(215, 12%, 42%)"
  secondary: "hsl(220, 16%, 94%)"
  accent: "hsl(220, 32%, 92%)"
  border: "hsl(220, 16%, 88%)"
  input: "hsl(220, 16%, 85%)"
  destructive: "hsl(0, 72%, 46%)"
  sidebar: "hsl(220, 45%, 13%)"
  sidebar-foreground: "hsl(215, 20%, 78%)"
  sidebar-accent: "hsl(220, 38%, 20%)"
  status-rojo: "#dc2626"
  status-rojo-text: "#b91c1c"
  status-ambar: "#f59e0b"
  status-ambar-text: "#b45309"
  status-verde: "#059669"
  status-verde-text: "#047857"
  status-azul: "#0284c7"
  status-azul-text: "#0369a1"
typography:
  display:
    fontFamily: "Poppins, Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.875rem"
    fontWeight: 600
    lineHeight: 1.2
    letterSpacing: "-0.025em"
  headline:
    fontFamily: "Poppins, Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 600
    lineHeight: 1.55
    letterSpacing: "-0.025em"
  figure:
    fontFamily: "Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 600
    lineHeight: 1.25
    fontFeature: "\"tnum\""
  body:
    fontFamily: "Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.43
  row-title:
    fontFamily: "Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "15px"
    fontWeight: 500
    lineHeight: 1.375
  label:
    fontFamily: "Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
    lineHeight: 1.33
  code:
    fontFamily: "ui-monospace, SFMono-Regular, Menlo, monospace"
    fontSize: "11px"
    fontWeight: 600
rounded:
  sm: "4px"
  md: "6px"
  lg: "8px"
  xl: "12px"
  panel: "16px"
  full: "9999px"
spacing:
  row-y: "10px"
  row-x: "12px"
  panel: "20px"
  gap: "24px"
  hero-x: "32px"
  hero-y: "28px"
components:
  hero-band:
    backgroundColor: "{colors.brand-navy}"
    textColor: "#ffffff"
    typography: "{typography.display}"
    rounded: "{rounded.panel}"
    padding: "28px 32px"
  panel:
    backgroundColor: "{colors.card}"
    rounded: "{rounded.panel}"
    padding: "20px"
  list-row:
    rounded: "{rounded.lg}"
    padding: "10px 12px"
  list-row-hover:
    backgroundColor: "{colors.muted}"
  area-pill:
    backgroundColor: "{colors.background}"
    textColor: "{colors.foreground}"
    typography: "{typography.label}"
    rounded: "{rounded.full}"
    padding: "4px 12px"
  deadline-tag:
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "2px 8px"
  sig-code:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.primary}"
    typography: "{typography.code}"
    rounded: "{rounded.sm}"
    padding: "2px 6px"
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.primary-foreground}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    height: "40px"
    padding: "8px 16px"
  button-outline:
    backgroundColor: "{colors.background}"
    textColor: "{colors.foreground}"
    rounded: "{rounded.md}"
    height: "40px"
    padding: "8px 16px"
---

# Design System: CMK GROUP

## Overview

**Creative North Star: "The Consultant's Ledger"**

CMK's world is a navy-and-paper office tool: a deep navy anchor (the sidebar and, on the dashboard, a full-width hero band) set against a warm off-white page and white working surfaces. It reads like a well-kept compliance ledger: every line is a fact with a status, a code from CMK's M01–M20 map, and a link to the record where it is acted on. Density is moderate and desktop-first; panels hold lists of rows rather than grids of equal cards.

Color is mostly quiet. Navy carries identity; the only loud color is the status code (red, amber, green, sky, gray), and it always travels with a word. The Colombian legal minimums are visual first-class citizens: the Res. 0312 score is drawn as a ring with its legal bands, and the 0312 indicators as real 12-month sparklines against their target.

The rest of the app (module screens built on shadcn/ui) keeps the incumbent, tighter look: 6–8px radii, bordered tables, standard buttons. The dashboard vocabulary below (hero band, 16px panels, row lists, ring, sparklines, area pills) is the system's newest layer and the reference for summary surfaces.

**Key Characteristics:**
- Navy anchor, warm paper page, white panels with hairline borders.
- Status is dot + word, never color alone.
- Row lists inside one bordered panel; no nested cards.
- Poppins for headings, Inter tabular for every figure, monospace only for codes.
- Flat surfaces; depth from tone (navy vs paper vs white), not shadow.

## Colors

A one-accent palette: navy and its tints for identity, cool-gray neutrals on a warm paper ground, and a separate five-word status code.

### Primary
- **CMK Navy** (`primary`, light theme): buttons, links, the "Abrir" and "Registrar lecturas" actions, the sparkline stroke when an indicator is in target, the tint behind SIG codes. In the dark theme `--primary` flips to a pale blue-gray (hsl 210 28% 90%), so it must not be used where a fixed navy is meant.
- **Logo Navy** (`brand-navy`): the fixed navy of the logo and the browser theme color. The dashboard hero band uses it literally in light mode; in dark mode the band shifts to **Hero Navy, Dark** (`hero-navy-dark`) with a 1px white/10 ring so it separates from the near-black page.
- **Sidebar Navy** (`sidebar`, `sidebar-accent`): the permanent navigation rail; the accent step marks the active and hovered item.

### Neutral
- **Warm Paper** (`background`): the page ground, a barely warm off-white taken from the logo's paper. Also the fill of area pills, so they read as cut from the page.
- **White Card** (`card`): every panel surface.
- **Ink Navy** (`foreground`): body text, a navy-tinted near-black.
- **Slate Muted** (`muted-foreground`): context lines, counts, meta and the dashed target line.
- **Fog** (`muted`, `secondary`, `accent`): hover fill for rows (muted at 60%), secondary buttons, soft accents.
- **Hairline** (`border`, `input`): panel borders, dividers between area groups, input strokes.
- **Alarm Red** (`destructive`): destructive buttons only; status red uses the status code below.

### Status code
- **Rojo** (`status-rojo` dot, `status-rojo-text` word): requires action; overdue, out of target, high-priority alert, 0312 below 60.
- **Ámbar** (`status-ambar` / `status-ambar-text`): soon; due within the window, alerts to review, 0312 from 60 to 85.
- **Verde** (`status-verde` / `status-verde-text`): in target, al día, 0312 above 85.
- **Azul** (`status-azul` / `status-azul-text`): upcoming, beyond the due-soon window.
- **Gris** (muted-foreground at 40% for the dot, muted-foreground for the word): no data, not measured.

Dark theme uses the 400 steps for dots and words and 950/60 fills for tags; on the navy hero the lighter 300 steps carry figures and ring strokes.

### Named Rules
**The Never-Color-Alone Rule.** A status is a 8px dot plus a word ("Vencido", "En meta", "Sin medir"), or a tinted tag carrying the word. A colored number or a colored dot without its word is not a status.

**The Fixed Navy Rule.** Anything that must stay navy in both themes (the hero band) uses `brand-navy` / `hero-navy-dark` directly, never `primary`.

**The Zero Is White Rule.** On the hero, a count of zero stays white with no dot: nothing to attend means no alarm color.

## Typography

**Display Font:** Poppins (with Inter, system sans fallback), loaded 500/600/700 from bunny.net
**Body Font:** Inter (with system sans), loaded 400/500/600/700
**Label/Mono Font:** the system monospace stack, for codes only

**Character:** Poppins' geometric headings echo the CMK wordmark; Inter does all the reading and all the numbers, set tabular so columns of figures align.

### Hierarchy
- **Display** (Poppins 600, 24px rising to 30px at sm, tight tracking): the company or portfolio name in the hero band; the ring's center figure.
- **Hero figure** (Poppins 600, 24px, tabular): the counts in the hero summary sentence.
- **Headline** (Poppins 600, 18px, tight tracking): panel titles ("Qué hacer hoy", "Salud del sistema", "Por área", "Clientes").
- **Figure** (Inter 600, 16px, tabular): values in compliance and indicator rows.
- **Row title** (Inter 500, 15px, snug, up to two lines): the task in "Qué hacer hoy".
- **Body** (Inter 400/500, 14px): row titles in other lists, descriptions, hero sub-lines.
- **Label** (Inter 500–600, 12px): status words, deadline tags, context lines, group headings inside a panel (sentence case, muted, semibold).
- **Code** (monospace 600, 11–12px): SIG map codes (M05) and indicator codes (IF-AT).

### Named Rules
**The Tabular Figures Rule.** Every count, score, percentage and target is set with tabular numerals. Decimals use the Colombian comma ("7,25", "68,0 %").

**The Mono Is For Codes Rule.** Monospace appears only on codes from CMK's map or the 0312 indicator list; never for labels, headings or numbers.

**The Sentence Case Rule.** Panel and group headings are sentence case; no uppercase tracked kickers above titles.

## Layout

Page content sits in a centered container up to 90rem with 16px padding (24px from md) and generous bottom space (96px). Sections stack with a 24px gap.

Summary surfaces follow a fixed order: a full-width navy hero band, then a 12-column grid from lg (7 + 5 columns: the action list wide, the health panel narrow; for the consultant 5 + 7, clients beside the consolidated list), then the "Por área" strip full-width. Below lg everything stacks in that same order, which is also the priority order.

Inside panels, rows have 12px horizontal and 10px vertical padding with 2px between rows; panel padding is 16px, 20px from sm. On phones, a row's deadline tag drops under the title so the title keeps the width.

## Elevation & Depth

Flat. Depth comes from tone: navy band over warm paper, white panels with a hairline border over paper, and a muted fill on hover. The dashboard uses no shadows. Incumbent shadcn primitives carry their own small shadows (cards at shadow-xs, popovers and dialogs larger); those are inherited, not part of the summary-surface vocabulary.

### Named Rules
**The Flat Panel Rule.** Panels and rows have no shadow at rest or on hover; hover is a muted fill (60%) and, for pills, a navy-tinted border.

## Shapes

Two radius families. The incumbent UI derives from a 8px base (`--radius`): 4px for small chips like SIG codes, 6px for buttons, inputs and deadline tags, 8px for cards and list rows. The dashboard adds 12px for interactive areas inside the hero and 16px for the hero band and every panel. Pills, dots and the ring are fully round. Borders are 1px hairlines; no side stripes or accent borders on any edge.

## Components

### Buttons
- **Shape:** gently rounded (6px), 40px high (36px small, 44px large).
- **Primary:** CMK Navy fill, paper-white text, 14px Inter 500; hover drops to 90% opacity.
- **Outline / Secondary / Ghost:** hairline input stroke on page fill, fog fill, or bare; hover takes the accent fill.
- **Text action:** navy 14px Inter 500 with a trailing arrow ("Abrir", "Registrar lecturas"); the in-panel expand control is a full-width text button with a 5% navy hover fill.
- **Focus:** 2px ring in the ring navy, offset 2px.

### Chips
- **SIG code:** navy text on 10% navy tint, 4px radius, monospace 600. Bare variant (navy at 80%, no fill) inside dense rows.
- **Deadline tag:** status-tinted fill (50 step light, 950/60 dark) with 800-step text, 6px radius, 12px Inter 500. Words: "Vencido hace 3 d", "Vence hoy", "En 12 días", "Prioridad alta", "Revisar".
- **Area pill:** fully round, warm paper fill, hairline border, status dot, module title, and the status word when not green. Hover: muted fill and navy-tinted border.

### Cards / Containers
- **Panel:** white, 16px radius, 1px hairline border, 16–20px padding, Poppins 18px title row with an optional muted count or note on the right. A panel holds rows, never other cards.
- **Corner Style:** 16px for panels; incumbent shadcn Card stays at 8px.
- **Shadow Strategy:** none (see Elevation & Depth).

### Inputs / Fields
- **Style:** incumbent shadcn input: 1px input stroke, 6px radius, page background.
- **Focus:** 2px ring navy.

### Navigation
- **Sidebar:** permanent navy rail with modules grouped into collapsible areas; foreground in pale slate, active and hover on the accent navy step, white for the primary item.
- **Mirror:** the dashboard's "Por área" strip lists areas in the same order as the sidebar (derived from the same source), so the map on the dashboard is the map in the menu.

### Hero Band (signature)
Full-width fixed-navy band, 16px radius, 28px/32px padding from sm. Left: company name in display Poppins, a muted NIT/city line, then one sentence of counts (Poppins tabular figures, red-300 or amber-300 with a dot when above zero, white when zero, 75% white words). Right: the 0312 ring with "Estándares mínimos", the band word with its dot, and a link line; the whole group is one link with a 5% white hover fill.

### 0312 Ring (signature)
A circular gauge of the Res. 0312 score. Stroke width is size/13 (minimum 6px), round caps, track at white/12 on navy or muted on light. The stroke color is the band: below 60 rojo "Crítico", 60 to 85 ámbar "Moderadamente aceptable", above 85 verde "Aceptable". Center figure in Poppins 600 30px with a smaller "%" and a "Res. 0312" caption; the 44px variant (client rows) shows only the figure. Fills once on arrival over 1s ease-out, only under motion-safe. No score shows "—" and no stroke.

### Indicator Row and Sparkline (signature)
Two lines: code (mono) and truncated name on top; below, a 104–112 x 32px sparkline of the 12 months, the value (16px tabular), the target ("meta ≥ 8"), and the status. The line is navy (sky-300 in dark) when in target, red when not; the target is a 1px dashed line (2 3) in muted-foreground at 45%. A month without a reading breaks the line instead of plotting zero; a lone month is a 2.5px dot. Each month exposes its value on hover. Unmeasured indicators collapse into one row listing their codes.

### Action List Row
Status dot (10px) aligned to the first line, two-line title, a muted context line led by the SIG code, and the deadline tag at the right. Rows are links (or buttons in the consultant view) with 8px radius and the muted hover fill. The list shows the top six; the rest open grouped by urgency (Urgente, Esta semana, Para revisar, Más adelante) under small muted semibold headings with counts. The empty state is one emerald-tinted message, not an illustration.

## Do's and Don'ts

### Do:
- **Do** pair every status color with its word: dot + word, or tinted tag + word.
- **Do** use the five-word code consistently: rojo = acción, ámbar = pronto, verde = en meta, azul = próximo, gris = sin dato.
- **Do** use `brand-navy` (light) and `hero-navy-dark` with a white/10 ring (dark) for the hero band, never `primary`.
- **Do** set every figure in Inter with tabular numerals and the Colombian decimal comma.
- **Do** build summary panels as one white 16px-radius bordered section holding rows with 8px radius and a muted hover fill.
- **Do** draw 0312 scores with the ring and its legal bands (<60, ≤85, >85), and indicator trends as the real 12 months with a dashed target line.
- **Do** order areas exactly as the sidebar does.
- **Do** put what needs action first and collapse what has no data into a single line.

### Don't:
- **Don't** signal status with color alone, including a colored number without a dot or word.
- **Don't** nest cards inside panels; use rows and dividers.
- **Don't** add colored side stripes or accent borders to rows or panels.
- **Don't** put uppercase tracked kickers or eyebrows above headings.
- **Don't** use monospace for anything but SIG and indicator codes.
- **Don't** plot a missing month as zero, or invent a trend where there are no readings.
- **Don't** add shadows to panels or rows.
