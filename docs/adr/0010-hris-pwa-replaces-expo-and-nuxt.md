# ADR-0010: HRIS PWA replaces Expo and Nuxt

Date: 2026-10-16. Status: accepted. Replaces the client plan in ADR-0009 (Expo crew app + Nuxt console).

## Context

HRIS crew distribution over iOS through the App Store needs a company Apple
Developer account and a D-U-N-S number. Neither exists yet, and both take an
uncertain time. The Talenta subscription ends March 2027, with a parallel run
in Jan-Feb 2027, so distribution cannot wait for Apple paperwork.

## Decision

One app: `laksamana-hris/apps/web`, a Vite + React SPA installed as a PWA at
`hris.laksamanamuda.id` (API base from `VITE_API_BASE_URL`, pointing at
`https://dev-api.laksamanamuda.id/api/v1` during development).

- Router: React Router v7 in SPA mode with path routing, never hash. On iOS
  standalone a hash change can drop camera permission.
- Styling: Tailwind v4 with our own tokens. Accessible primitives from React
  Aria Components. No shadcn or other ready-made kit.
- Data: TanStack Query plus `@laksamana/api` (workspace package).
- Admin tables: TanStack Table. Excel export: SheetJS 0.20.x from the official
  tarball `https://cdn.sheetjs.com/xlsx-0.20.3/xlsx-0.20.3.tgz` (npm
  `xlsx@0.18.5` has a CVE). PWA shell: `vite-plugin-pwa`.
- Everyone signs in to the crew Beranda, because HR and Kepala Divisi also
  punch. Accounts with HR or approval scope see a "Kelola" entry to
  `/kelola/...`. Crew is mobile-first. Kelola is desktop-first; on phones the
  absence queue, request approvals and tickets stay fully usable, the rest is
  read-only.
- Login stays `POST /api/v1/auth/login {login, pin}` with the Bearer token in
  `localStorage` (`hris_token`). No device binding, no biometric lock (A9).
- Face stays on-device with `onnxruntime-web` WASM: the same YuNet + SFace
  int8 models, model id `sface-2021dec-int8`, cosine metric. `numThreads = 1`,
  no WebGPU. WASM files are self-hosted, never CDN, and cached by the service
  worker with the models. One singleton session per page load. Frame decode
  goes through canvas `getImageData` to RGB with the same pipeline (640px,
  YuNet 320x320, exactly one face, align 112, SFace 128-dim, L2 normalize).
  The threshold is recalibrated on web (`/dev/kalibrasi`). No liveness (A4).
  Enrolment photo stays mandatory (A3).
- Photos come only from the live camera: `getUserMedia({video:
  {facingMode: 'user'}})` with `<video playsinline muted autoplay>`, captured
  to canvas and encoded to JPEG 720px quality 0.7 (canvas re-encode strips
  EXIF), max 5 MB. No `<input type=file>`. Preview may mirror, capture never
  does. All tracks stop when the screen is left.
- Location comes from `navigator.geolocation.getCurrentPosition` (high
  accuracy, timeout) as `lat`, `lng`, `akurasi`. The `mock` signal is gone:
  the client never sends it and the server treats a missing field as false.
- Punching works online only. The service worker caches the app shell, wasm
  and models. Offline shows a clear message; an offline punch queue never
  exists.
- Install: iPhone must run from the Home Screen. Outside standalone mode
  (`display-mode: standalone` or `navigator.standalone`) the app shows the
  Share to Add to Home Screen guide and locks punching; sign-in and reading
  stay available. Android only suggests install via `beforeinstallprompt`
  when present. In-app browsers (WhatsApp, Instagram, Facebook, Line,
  TikTok; best-effort UA sniffing) get a "open in Chrome/Safari" message.
- No browser version floor. Feature detection decides: missing `getUserMedia`,
  WebAssembly or geolocation shows a specific message.
- No push, no geofence. Reminders become `perhatian[]` in
  `GET /hris/me/today`, computed by the server on request with no scheduler.
  Beranda renders it as a "Perlu perhatian" box.
- Visual: light mode only, white background with dark gold `#8B6914` for text,
  primary buttons and links (5.1:1, AA). `#C9A961` is decorative accent only,
  never text. VALID / MENUNGGU / DITOLAK status colors differ from brand gold
  and all pass AA. All colors are tokens, because the brand later moves to
  cream plus dark gold. No official logo yet: a simple token-built monogram
  placeholder, easy to swap. PWA icons: 192, 512, maskable, plus 180
  `apple-touch-icon`.
- Hosting: GitHub Actions builds a Docker image (nginx + `dist`) and pushes
  `ghcr.io/dwipapap/laksamana-hris:dev`; Dokploy pulls the finished image.
  Dokploy, DNS and `.env` on servers are manual steps outside PRs.

## Consequences

- Push notifications are gone, with their scheduler, device tokens and
  reminder log. Late-crew and approval nudges arrive only when the crew opens
  Beranda (`perhatian[]`), never in the background.
- Background geofence is gone, with its ENTER/EXIT events. No automatic
  punch existed and none appears; the two geofence reminders disappear.
- The `mock` location signal is gone. Rooted or emulated locations are no
  longer flagged separately; distance, face and audit photo carry the check.
- WASM memory on iPhone sits around 300-500 MB per tab; exhaustion reloads
  the tab with no error. The singleton model session and the online-only
  punch flow exist to survive that limit.
- iPhone crew must install to the Home Screen before punching. Support load
  moves from App Store review to install guidance.
- The Expo (`apps/mobile`) and Nuxt (`apps/console`) clients are deleted in
  `laksamana-hris` PR #9. Their code stays readable at commit `68686fc` as a
  logic reference: face math plus tests move over as-is, the ONNX models move
  over, crew screens and HR pages are ported by function, never pasted by
  look.
