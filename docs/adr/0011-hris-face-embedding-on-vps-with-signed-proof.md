# ADR-0011: Face embedding on the VPS with a signed proof

Date: 2026-10-19. Status: accepted. Amends the "Face stays on-device" clause
of ADR-0010 (it does not replace that ADR: models, pipeline and thresholds
are unchanged).

## Context

ADR-0010 keeps face inference on the crew phone: the PWA computes the SFace
128-float descriptor with `onnxruntime-web` and sends it to the API, which
matches it against the enrolled descriptor. A rooted phone (or a script
calling the API directly) can therefore send an arbitrary descriptor — for
example someone else's stolen embedding — and the server cannot tell a
computed face from an invented one. GPS and the audit photo raise the bar,
but neither binds the descriptor itself.

## Decision

The VPS signs the on-device embedding. After the PWA computes the descriptor
it asks the VPS for a short-lived signed proof (S3):

- Token: `base64url(payload JSON) + "." + base64url(HMAC-SHA256 over the
  payload bytes)`, compared with `hash_equals`.
- Payload keys, in order, without spaces: `v,kid,model,descriptor,
  fotoSha256,iat`.
- Verification (server time only): known `kid` (active `HRIS_FACE_KID` or
  previous `HRIS_FACE_KID_LAMA` during rotation), `model` equal to the
  request `model` field, age at most 120 s with 30 s future clock-skew
  tolerance, and — when a photo is sent — `fotoSha256` equal to the SHA-256
  of the decoded photo bytes.
- A valid proof's descriptor replaces the client field on punch, enrol
  (one proof per descriptor; the straight enrolment photo binds the first),
  pre-send check and top-up. Any failure answers `422
  bukti_wajah_tidak_valid`.
- `HRIS_FACE_BUKTI_WAJIB` (default false) makes the proof mandatory once
  clients can mint one: a punch without a proof then ignores the client
  descriptor and queues (`WAJAH`/`WAJAH_KOSONG`); enrol, check and top-up
  without a proof answer `422`. With the flag off, proof-less requests
  behave exactly as before, so rollout needs no flag day.
- With an empty `HRIS_FACE_SECRET` every proof is refused and the mandatory
  flag counts as false (legacy behaviour).
- Face audits (`wajah_dicek`, `wajah_didaftarkan`, `wajah_dilengkapi`,
  `wajah_duplikat_ditolak`) record `sumber_wajah` — `server` (verified
  proof), `klien` (client field) or `tanpa` (nothing matched) — and never
  descriptor bytes.

## Consequences

- A stolen descriptor alone no longer punches: the attacker also needs a
  fresh proof, which only the VPS mints for a live capture. Replay is bound
  by the 120 s lifetime plus the photo hash.
- The on-device model id (`sface-2021dec-int8`), the `.onnx` weights and the
  `FaceMatcher` thresholds are untouched; matching logic and calibration
  (W6) keep working unchanged.
- Rotation is two-key (`_LAMA` pair) so the signing secret can change
  without locking out crew mid-shift.
- The proof adds ~2 KB per request; no new routes, no new tables, no new
  audit volume beyond one `sumber_wajah` key.
- Until the PWA mints proofs, the flag stays off and the server cannot
  distinguish computed from invented descriptors — the rollout order is
  server first, flag last.
