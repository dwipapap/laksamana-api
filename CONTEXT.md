# Laksamana

The Office of Laksamana Muda: one internal staff system, served by one API over the company's databases. Canonical terms are Indonesian, as the data, screens and error codes are; definitions are in English.

## Language

### Office

**Office**:
Laksamana Muda's internal staff system as a whole; only Users with a Sesi can use it, whichever frontend renders it.
_Avoid_: Portal, Dashboard, Backoffice, Admin panel

**Beranda**:
The Office's front page, where a User sees the Kartu of the Modul they have Akses to.
_Avoid_: Launcher, Portal, Home, Anjungan

**Kartu**:
A tile on the Beranda that opens one or more Modul (e.g. the Stock kartu opens Ordering, Purchasing, Resep, Pemakaian, HPP).
_Avoid_: Tile, App, Module

**Panel**:
The screens a single Modul opens (e.g. Panel Brankas); one Kartu may hold several Panels.
_Avoid_: Aplikasi, Page, Screen

**Modul**:
A unit of Akses a User can be given; each Modul opens one Panel.
_Avoid_: Module (for a Backend), Menu, Feature, Aplikasi

**Backend**:
One of the legacy server programs with its own database (`stock`, `finance`, …) that the API replaces; several Modul may live in one Backend.
_Avoid_: Module, Service

### Identity & access

**User**:
A staff member who logs in to the Office, identified by name/username and PIN.
_Avoid_: Akun, Account, Office account, Crew, Kru, Staf

**User Nonaktif**:
A User who can no longer open a Sesi but keeps their Roster entry and Akses, so they can be reactivated; deleting a User is a separate, final step that requires this state first.
_Avoid_: Disabled, Archived, Suspended

**Sesi**:
A period in which a User is logged in; every action a User takes happens inside a Sesi and is attributed to that User's name.
_Avoid_: Login, Session token

**Akses**:
Whether a User may open a Modul, managed in Kelola Akses. It is the result of Izin Akses, Larangan and Akses Bawaan together.
_Avoid_: Grant, Permission

**Izin Akses**:
One recorded decision giving a User Akses to a Modul, or to every active Modul (`*`).
_Avoid_: Grant

**Larangan**:
A recorded decision that takes a Modul away from a User; it beats an Izin Akses for `*`.
_Avoid_: Deny grant, Revoke

**Akses Bawaan**:
Akses a User gets without an Izin Akses, because of their Tim, being an Admin Modul, or being a Kepala Divisi.
_Avoid_: Built-in access, Default access

**Modul Terbatas**:
A Modul that an Izin Akses for `*` does not reach; the User needs a qualifying Tim, admin rights, headship, or an Izin Akses for that Modul itself (today only `dw`).
_Avoid_: Restricted module

**Akses Halaman**:
Access inside one Panel, per page, set by that Panel's own admins (e.g. Kas and Brankas in finance); separate from Akses.
_Avoid_: Akses (for this tier), Page permission

**Tingkat**:
The level of Akses Halaman a User has on one page (0–2).
_Avoid_: Level, Role

**Peran**:
A User's role inside one Panel, which shapes their Akses Halaman.
_Avoid_: Role (for Office-wide rights), Jabatan

**Lingkup**:
Whose data a User may see or change on a page that shows per-person data: only their own (`sendiri`), their Divisi’s if they are its Kepala Divisi (`divisi`), or everyone’s (`semua`). Aggregates without names stay visible.
_Avoid_: Scope, Visibility

**Kewenangan**:
A named action a Peran may take in a Modul that is not tied to one page, such as correcting stock or reopening a closed Hari Operasional.
_Avoid_: Permission, Ability, Hak (alone)

**Admin Modul**:
A User with admin rights on one Modul: they can change that Panel's settings and data beyond normal use.
_Avoid_: Module admin, Moderator

**Superadmin**:
A User who is Admin Modul of every Modul (`*`); only they manage Users, PINs and Kelola Akses. There must always be at least one.
_Avoid_: Admin Utama, Root, Owner

### People & organisation

**Roster**:
The list of every User with their HR data (branch, organisation, job position, employment status, join date, phone).
_Avoid_: Staff list, Directory, Crew list

**Pengelola Roster**:
A User who may add, change, deactivate and remove Users on the Roster (Tim HRD/HR), but not a Superadmin and without seeing PINs.
_Avoid_: HR admin, User manager

**Tim**:
A User's free-text job label (e.g. "Kitchen Bar", "Kasir Office", "Head Marketing"); its words decide Akses Bawaan, Divisi and Leader.
_Avoid_: Team, Keterangan, Organization

**Divisi**:
A unit of the organisation, one list for every Modul (owner, Q2). A **shift** Divisi (bar, kitchen, floor, cashier) is where shift crew work; Jadwal, Penempatan Divisi and Kepala Divisi only ever name these. A **kantor** Divisi (Finance, Event, Marketing & Digital, …) groups office staff for HR, Akademi and KPI. In Jadwal a User belongs to at most one shift Divisi: their Penempatan Divisi if set, otherwise the first Divisi their Tim names, unless the Tim marks them as office staff. Separately, their Karyawan record names the one Divisi they belong to in the organisation.
_Avoid_: Department, Tim, Organization (the Roster field is separate free text)

**Nonshift**:
A User outside every Divisi: office staff (Tim says Office/Kantor) or anyone whose Tim names no Divisi.
_Avoid_: Office Divisi, Unassigned

**Penempatan Divisi**:
A manual placement of one User into a Divisi (or Nonshift) that overrides what their Tim says.
_Avoid_: Override, Divisi assignment

**Kepala Divisi**:
A User listed as heading a Divisi; they may edit the schedule of that Divisi's crew and receive its requests first.
_Avoid_: Head, Leader, Manager

**Leader**:
A User whose Tim marks them as a head (the word "Head"), which counts for leader bonuses; unrelated to Kepala Divisi.
_Avoid_: Head, Kepala Divisi

**Karyawan**:
The HR record of a User: their organisational Divisi, their superior, contract and probation dates; one per User. Every Karyawan is a User; a Pekerja Harian, Talent or Klien never is.
_Avoid_: Employee, Pegawai, Staf, Kru

**Pihak**:
A person or organisation outside the Users that the company deals with. What it is to us is a role it holds — vendor, Klien, Talent, KOL, Pekerja Harian — and one Pihak may hold several.
_Avoid_: Party, Kontak, Partner, Customer

**Klien**:
A Pihak that books or buys events and packages through Marketing, followed up by a Marketing PIC.
_Avoid_: Client, Customer, Tamu (a walk-in or reservation guest)

**Talent**:
A Pihak that performs at the venue (band, DJ, singer) and is paid per performance.
_Avoid_: Artist, Performer, Pengisi acara

**KOL**:
A Pihak that promotes the venue on social media for Konten, paid per post or visit.
_Avoid_: Influencer, Endorser

**Buyer**:
A member of the public who buys event tickets on the public ticket shop with an email and password. A Buyer is not a User: separate accounts (`tix_users`), their own sessions, no Akses, and never listed on the Roster.
_Avoid_: Pembeli (in code), Customer account, User, Member

**Pekerja Harian**:
A daily worker hired per need; a Pihak, not a User, never logs in to the Office, identified by phone number, and may work in several Divisi.
_Avoid_: DW (as a person), Kru, Freelancer, Part-timer

### Operations & stock

**POS**:
The cashier system the company runs, ESB (Esensi Solusi Buana); the source of bills, payments and menu sales, and the company’s accounting. The Office supports it and does not replace it (ADR-0008). Code keeps the name `esb`.
_Avoid_: ESB (on screens), Kasir (for the system)

**Lokasi**:
A place that holds stock and money and has its own operating hours: today the one Outlet and the Central Kitchen, each counted separately.
_Avoid_: Cabang, Store, Gudang (unless it is one)

**Hari Operasional**:
One opening of a Lokasi, from when it is opened to when it is closed; everything that happens inside that span belongs to its `tanggal_bisnis`, even after midnight.
_Avoid_: Sesi (a login), Shift (crew working hours), Hari (alone)

**Barang**:
Anything the company buys, stores or uses as an ingredient; one catalogue shared by Stock and HPP.
_Avoid_: Produk (for an ingredient), Bahan (as a separate list), Item

**Satuan Dasar**:
The smallest unit a Barang’s stock is counted in (Gram, Ml, Pcs); every quantity is stored in it.
_Avoid_: Base unit, Satuan Terkecil

**Ukuran Satuan**:
How many of a Barang’s Satuan Dasar one of its other units holds (1 Kg = 1000 Gram, 1 Ekor = 4 Pcs); set per Barang, because the ratio differs per Barang.
_Avoid_: Konversi (alone), Isi Pack

**Pesanan Bahan**:
An order of raw materials (Barang) to a vendor, from Stock’s Ordering and Purchasing; kept separate from PO Proyek.
_Avoid_: PO (alone), Order

**PO Proyek**:
A purchase order raised in BD for a project or event; a different flow from Pesanan Bahan.
_Avoid_: PO (alone), Purchase request

**Pembelian Langsung**:
A purchase paid on the spot without any order to a vendor (e.g. cash at the market); a document of its own, never a Pesanan Bahan.
_Avoid_: Belanja PO, Direct order

**Tagihan Vendor**:
A vendor’s bill, paid as a Pembayaran to that vendor; one Tagihan Vendor may cover several orders.
_Avoid_: Invoice (for our own invoices), Nota

**Acara**:
A Klien's booking of the venue and F&B (gathering, birthday, package) sold by a Marketing PIC; it moves Lead → Prospect → Approval → Quotation → Deal → done, or Lost, with its quotation lines, payments and tasks.
_Avoid_: Event (the EMS record the venue runs itself), Booking, Order

**Dompet**:
A place company money sits whose balance the Office computes: a bank account, the brankas cash, or a Kas Kecil pos. Its balance is its opening balance plus every Arus Kas, never a stored number.
_Avoid_: Wallet (on screens it is fine), Wadah, Rekening (for the cash or a pos), Account

**Arus Kas**:
One amount going into or out of a Dompet, always pointing at the one document that moved it.
_Avoid_: Mutasi (alone), Transaction, Cash flow statement

**Kas Kecil**:
The petty-cash book: transactions split over pos, each marked whether it is already in the books and whether its receipt (bon) is in hand.
_Avoid_: Petty cash, Kas (alone)

**Mutasi Wallet**:
Money moved by hand between two Dompet, or put into or taken out of one from outside the Office.
_Avoid_: Transfer (alone), Mutasi (alone)

**Setoran**:
Cash carried from the brankas to a bank, covering the takings of one or more days; never more than what is left of each day's cash.
_Avoid_: Deposit, Setor (as a noun)

**Planning Pembayaran**:
The sheets of payments to be made on a payment date, grouped by the Dompet they are paid from; a payment only lowers a balance once it is marked paid.
_Avoid_: Payment plan, Jadwal bayar

**Pembayaran**:
One line of Planning Pembayaran: what is paid, to whom if anyone, from which Dompet, and when it was paid and proven.
_Avoid_: Payment, Transfer

**Metode Bayar**:
How a guest paid (cash, QRIS BRI, EDC BCA, QR Order, …), and the Dompet that money lands in.
_Avoid_: Payment type, Channel

**Investor**:
A Pihak that put capital into the company and is paid it back over time from a Dompet (Pengembalian Modal).
_Avoid_: Shareholder, Pemodal

**Opname**:
A physical count of the stock of one Lokasi, compared with what the system expects.
_Avoid_: Stock take, SO (alone)

**Penyesuaian Stok**:
A correction that brings system stock in line with an Opname, decided by a User holding that Kewenangan (meant for Heads, see L11 in `docs/erp/pertanyaan-owner.md`) and always carrying a written reason; it never edits past movements.
_Avoid_: Koreksi (alone), Adjustment

