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
One of the four shift units — bar, kitchen, floor, cashier — that shift crew work in. A User belongs to at most one: their Penempatan Divisi if set, otherwise the first Divisi their Tim names, unless the Tim marks them as office staff.
_Avoid_: Department, Tim, Organization (the HR field is separate data)

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

**Pekerja Harian**:
A daily worker hired per need; not a User, never logs in, identified by phone number, and may work in several Divisi.
_Avoid_: DW (as a person), Kru, Freelancer, Part-timer
