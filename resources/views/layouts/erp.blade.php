<!DOCTYPE html>
<html>

<head>
    <title>Noud ERP</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('layouts.partials._favicon')
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- CSS -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tom-select/dist/css/tom-select.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tom-select/dist/js/tom-select.complete.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary: #22c55e;      /* Green */
            --primary-dark: #15803d; /* Dark Green */
            --accent: #eab308;       /* Yellow/Gold */
            --bg: #f8fafc;
            --sidebar-dark: #0f172a; /* Navy */
        }

        .ts-dropdown {
            z-index: 9999 !important;
        }

        .ts-control {
            z-index: 10;
        }

        .ts-wrapper {
            position: relative;
            z-index: 50;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Outfit', sans-serif;
            background: #f0f2f5;
            overflow-x: hidden;
        }

        .wrapper {
            display: flex;
            min-height: 100vh;
            width: 100vw;
            background: #f0f2f5;
        }

        /* ══ FULL-HEIGHT FLAT SIDEBAR ══ */
        .sidebar {
            width: 280px;
            flex-shrink: 0;
            background: #0d3d2a;
            height: 100vh;
            color: #ffffff;
            padding: 2rem 1rem;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            position: sticky;
            top: 0;
            transition: width 0.18s ease, padding 0.18s ease;
            /* Sidebar di atas .content (z-auto). position:sticky bikin stacking context tersendiri,
               jadi tooltip menu (z-9999 di dalamnya) tetap di-clip ke stack sidebar.
               Naikkan z-index sidebar supaya tooltip & floating submenu tidak ketutup task card
               (atau konten lain di .content). Tetap di bawah modal (z-1100+). */
            z-index: 100;
        }

        /* Toggle collapse button (top-right of sidebar header) */
        .sidebar-toggle {
            position: absolute;
            top: 14px;
            right: 14px;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            color: rgba(255, 255, 255, 0.75);
            border: 1px solid rgba(255, 255, 255, 0.1);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            line-height: 1;
            z-index: 5;
            transition: background 0.15s, color 0.15s, transform 0.18s ease;
        }
        .sidebar-toggle:hover {
            background: rgba(255, 255, 255, 0.16);
            color: #fff;
        }

        /* ── Collapsed mode (icon-only strip) ── */
        .sidebar.collapsed { width: 78px; padding: 1rem 0.5rem; overflow: visible; }
        /* sidebar-nav tetap scrollable saat collapsed; floating panel sudah di-portal ke <body>
           sehingga tidak ke-clip oleh overflow. Padding-right di-nolkan supaya icon center
           simetris (kalau ada padding-right, icon ter-shift ke kiri). */
        .sidebar.collapsed .sidebar-nav {
            overflow-y: auto;
            overflow-x: visible;
            padding-right: 0 !important;
        }
        .sidebar.collapsed .sidebar-header { padding: 0 0 1.5rem 0; align-items: center; }
        .sidebar.collapsed h3 { font-size: 1rem; text-align: center; letter-spacing: 0; }
        .sidebar.collapsed .sidebar-brand-sub { display: none; }
        .sidebar.collapsed .sidebar-toggle { top: 6px; right: 50%; transform: translateX(50%) rotate(180deg); }
        .sidebar.collapsed .menu-label,
        .sidebar.collapsed .arrow,
        .sidebar.collapsed .user-info { display: none !important; }
        .sidebar.collapsed .menu-title,
        .sidebar.collapsed .menu-single {
            justify-content: center !important;
            padding: 7px 0 !important;
            text-align: center;
            width: 100%;
            box-sizing: border-box;
            margin-bottom: 2px;
        }
        /* Force outer wrapper-span isi icon untuk full-width center, biar icon benar di tengah */
        .sidebar.collapsed .menu-title > span:first-child {
            display: flex !important;
            align-items: center;
            justify-content: center;
            flex: 1;
        }
        /* ── Icon: monoline outline SVG (mirip Accurate) — putih, ringan, no tile ── */
        .menu-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            background: transparent;
            border-radius: 6px;
            color: rgba(255, 255, 255, 0.78);
            line-height: 0;
            flex-shrink: 0;
            transition: color 0.15s, background 0.15s;
        }
        .menu-icon svg { width: 22px; height: 22px; display: block; }
        .menu-title:hover .menu-icon,
        .menu-single:hover .menu-icon {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.08);
        }
        .menu-group.active .menu-icon,
        .menu-single.active .menu-icon {
            color: var(--accent);
            background: rgba(234, 179, 8, 0.14);
        }

        .sidebar.collapsed .menu-icon { width: 34px; height: 34px; }
        .sidebar.collapsed .menu-single .menu-icon { width: 34px; height: 34px; }
        .sidebar.collapsed .menu-icon svg { width: 22px; height: 22px; }
        /* User profile (avatar di bawah) juga ikut center, override padding asimetris dari
           definisi default `.user-profile { padding: 12px 8px }` supaya avatar tepat di tengah.
           Inner-wrapper-div pakai inline `width:100%` + flex default flex-start, jadi avatar
           menempel ke kiri. Override `justify-content: center` di wrapper supaya avatar di tengah. */
        .sidebar.collapsed .user-profile {
            justify-content: center;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }
        .sidebar.collapsed .user-profile > div:first-child {
            justify-content: center !important;
            gap: 0 !important;
        }

        /* Tooltip on hover only for items WITHOUT submenu (Dashboard etc.).
           Pakai position:fixed + CSS var --tip-top (di-set JS pada mouseenter) supaya tidak
           ke-clip saat sidebar-nav scrollable. */
        .sidebar.collapsed .menu-single:hover::after {
            content: attr(data-tip);
            position: fixed;
            left: 86px;
            top: var(--tip-top, 0);
            background: #0f172a;
            color: #fff;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.78rem;
            white-space: nowrap;
            pointer-events: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 9999;
        }
        .sidebar.collapsed .menu-title,
        .sidebar.collapsed .menu-single { position: relative; }

        /* ── Floating submenu when collapsed ── */
        .sidebar.collapsed .menu-group { position: relative; }
        /* Hide inline menu-items (override .active that normally shows them) */
        .sidebar.collapsed .menu-group .menu-items,
        .sidebar.collapsed .menu-group.active .menu-items { display: none; }
        /* Floating panel: saat aktif, di-portal ke body via JS supaya bypass semua
           stacking context parent. Class .floating-portal ditambah JS, position via JS. */
        .menu-items.floating-portal {
            display: flex !important;
            position: fixed;
            min-width: 220px;
            max-height: calc(100vh - 16px);
            overflow-y: auto;
            background: #0d3d2a;
            padding: 6px;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.45);
            z-index: 99999;
            flex-direction: column;
            gap: 1px;
            color: rgba(255,255,255,0.8);
            font-family: 'Outfit', sans-serif;
        }
        .menu-items.floating-portal > a {
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            display: block;
        }
        .menu-items.floating-portal > a:hover {
            background: rgba(255,255,255,0.08);
            color: #fff;
        }
        .menu-items.floating-portal > a.active {
            color: var(--accent);
            background: rgba(234, 179, 8, 0.15);
            font-weight: 600;
        }
        .menu-items.floating-portal .sub-menu-title {
            padding: 8px 12px;
            font-size: 0.85rem;
            color: rgba(255,255,255,0.85);
            cursor: default;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .menu-items.floating-portal .sub-menu-items {
            display: flex !important;
            flex-direction: column;
            padding-left: 4px;
            border-left: 2px solid rgba(255,255,255,0.1);
            margin-left: 8px;
        }
        .menu-items.floating-portal .sub-menu-items > a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            padding: 6px 12px 6px 16px;
            font-size: 0.8rem;
            border-radius: 6px;
            display: block;
        }
        .menu-items.floating-portal .sub-menu-items > a:hover {
            background: rgba(255,255,255,0.08);
            color: #fff;
        }
        .menu-items.floating-portal .sub-menu-items > a.active {
            color: var(--accent);
            background: rgba(234, 179, 8, 0.15);
            font-weight: 600;
        }
        .menu-items.floating-portal .sub-arrow { display: none; }
        /* Header label di portal panel */
        .menu-items.floating-portal > .floating-header {
            display: block;
            padding: 6px 10px 8px 10px;
            margin-bottom: 4px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--accent);
            border-bottom: 1px solid rgba(255,255,255,0.12);
            cursor: default;
            user-select: none;
            background: rgba(0,0,0,0.15);
            border-radius: 4px 4px 0 0;
        }
        /* Floating header di-hide by default — hanya muncul saat panel dipindah ke body
           (rule .menu-items.floating-portal > .floating-header di atas). */
        .floating-header { display: none; }

        /* ── Embed mode: hide sidebar entirely (untuk iframe modal) ── */
        body.embed .sidebar { display: none !important; }
        body.embed .content { padding: 0.75rem; }
        body.embed .wrapper { width: 100%; }

        .sidebar-header {
            padding: 0 0.75rem 2rem 0.75rem;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .sidebar h3 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: -0.025em;
            color: var(--accent);
            text-transform: uppercase;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .sidebar-brand-sub {
            font-size: 0.7rem;
            color: rgba(255,255,255,0.5);
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        /* Navigation Area */
        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding-right: 5px;
        }

        /* Custom Scrollbar for Sidebar */
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-track { background: transparent; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }

        /* ══════════════════════════════════════════════════
           SIDEBAR MENU HIERARCHY (manager.io-inspired)
           3-tier indentation: 16px → 36px → 54px
           Tanpa garis pemisah tipis — pakai indent + weight + warna
           ══════════════════════════════════════════════════ */

        /* ─── Level 1: Menu Group (Kas & Bank, Sales, dst) ─── */
        .menu-group {
            margin-bottom: 2px;
        }

        .menu-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 11px 16px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.82);
            transition: background 0.15s, color 0.15s;
            user-select: none;
        }
        .menu-title:hover {
            background: rgba(255, 255, 255, 0.06);
            color: #ffffff;
        }
        .menu-group.active .menu-title {
            color: var(--accent);
            background: rgba(234, 179, 8, 0.08);
        }

        .arrow {
            font-size: 0.7rem;
            transition: transform 0.2s ease;
            opacity: 0.5;
        }
        .menu-group.active .arrow {
            transform: rotate(180deg);
            opacity: 1;
            color: var(--accent);
        }

        .menu-items {
            display: none;
            padding: 2px 0 6px 0;
            flex-direction: column;
            gap: 1px;
        }
        .menu-group.active .menu-items {
            display: flex;
        }

        /* ─── Level 2: Menu Item langsung (Pemasukan, Transfer, dst) ─── */
        /* Default link style (dipakai di semua level, di-override per level) */
        .sidebar a {
            color: rgba(255, 255, 255, 0.65);
            text-decoration: none;
            display: block;
            font-weight: 500;
            transition: background 0.15s, color 0.15s;
        }
        .sidebar a:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.05);
        }

        .menu-items > a {
            padding: 8px 16px 8px 36px;
            font-size: 0.875rem;
            border-radius: 8px;
        }
        .menu-items > a.active {
            color: var(--accent);
            background: rgba(234, 179, 8, 0.14);
            font-weight: 600;
            box-shadow: inset 3px 0 0 var(--accent);
        }

        /* ─── Level 2 (variant): Sub-menu Group (Pembayaran, Pemasukan ▾) ─── */
        .sub-menu-group { margin: 0; }
        .sub-menu-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 16px 8px 36px;
            font-size: 0.875rem;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.72);
            cursor: pointer;
            border-radius: 8px;
            transition: background 0.15s, color 0.15s;
        }
        .sub-menu-title:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.05);
        }
        .sub-menu-title.open {
            color: var(--accent);
            background: rgba(234, 179, 8, 0.08);
        }

        .sub-arrow {
            font-size: 0.6rem;
            opacity: 0.5;
            transition: transform 0.2s;
        }
        .sub-menu-title.open .sub-arrow {
            transform: rotate(180deg);
            opacity: 1;
        }

        /* ─── Level 3: Sub-menu Items (Pengeluaran Umum, Refund Customer, dst) ─── */
        .sub-menu-items {
            display: none;
            flex-direction: column;
            gap: 1px;
            padding: 2px 0 4px 0;
        }
        .sub-menu-items.open {
            display: flex;
        }
        .sub-menu-items > a {
            padding: 7px 16px 7px 54px;
            font-size: 0.825rem;
            color: rgba(255, 255, 255, 0.58);
            border-radius: 8px;
        }
        .sub-menu-items > a.active {
            color: var(--accent);
            background: rgba(234, 179, 8, 0.15);
            font-weight: 600;
            box-shadow: inset 3px 0 0 var(--accent);
        }

        /* Single Menu Item (Dashboard) */
        .menu-single {
            display: flex !important;
            align-items: center;
            gap: 12px;
            padding: 10px 14px !important;
            border-radius: 10px !important;
            font-size: 0.92rem !important;
            font-weight: 500 !important;
            margin-bottom: 3px;
        }

        /* ══ USER PROFILE SECTION ══ */
        .user-profile {
            margin-top: auto;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding-left: 8px;
        }

        .avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(45deg, var(--accent), var(--primary));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: #000;
            border: 2px solid rgba(255, 255, 255, 0.2);
            font-size: 1.1rem;
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 700;
            font-size: 0.9rem;
            color: #ffffff;
        }

        .user-role {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.5);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* User profile dropdown */
        .user-profile {
            position: relative;
            cursor: pointer;
            transition: background 0.2s ease;
            border-radius: 10px;
            padding: 12px 8px;
            margin-top: auto;
        }
        .user-profile:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        .user-profile .chevron {
            margin-left: auto;
            color: rgba(255, 255, 255, 0.4);
            font-size: 11px;
            transition: transform 0.2s ease;
        }
        .user-profile[data-open="true"] .chevron {
            transform: rotate(180deg);
            color: rgba(255, 255, 255, 0.85);
        }
        .user-menu-popup {
            position: absolute;
            bottom: calc(100% + 6px);
            left: 8px;
            right: 8px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            z-index: 60;
            transform-origin: bottom center;
        }
        .user-menu-popup .header {
            padding: 14px 16px;
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-bottom: 1px solid #e5e7eb;
        }
        .user-menu-popup .header .name {
            font-weight: 700;
            color: #0f172a;
            font-size: 14px;
            line-height: 1.2;
            margin-bottom: 2px;
        }
        .user-menu-popup .header .meta {
            font-size: 11px;
            color: #6b7280;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .user-menu-popup .role-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            margin-top: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .role-badge.super_admin { background: #f3e8ff; color: #7e22ce; }
        .role-badge.admin       { background: #dbeafe; color: #1d4ed8; }
        .role-badge.user        { background: #f1f5f9; color: #475569; }
        .user-menu-popup .item {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 11px 16px;
            font-size: 13px;
            color: #374151;
            background: transparent;
            border: none;
            cursor: pointer;
            text-align: left;
            transition: background 0.15s ease;
        }
        .user-menu-popup .item:hover {
            background: #f9fafb;
        }
        .user-menu-popup .item.danger {
            color: #dc2626;
        }
        .user-menu-popup .item.danger:hover {
            background: #fef2f2;
        }
        .user-menu-popup .item .icon {
            width: 16px;
            text-align: center;
            font-size: 14px;
        }
        .user-menu-popup .divider {
            height: 1px;
            background: #f3f4f6;
        }
        /* Saat sidebar collapsed: popup user menu (Logout dll.) muncul melayang ke kanan
           sidebar (bukan di atas avatar yang lebar-nya cuma 78px), supaya isinya tetap terbaca
           dan tombol Logout bisa diklik. */
        .sidebar.collapsed .user-menu-popup {
            left: calc(100% + 12px);
            right: auto;
            bottom: 0;
            width: 240px;
        }
        .sidebar.collapsed .user-profile .chevron { display: none; }

        /* ERP Standard Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 0.375rem;
            transition: all 0.2s;
            cursor: pointer;
        }

        .content {
            flex: 1;
            padding: 1.5rem;
            background: var(--bg);
            overflow-y: auto;
            width: 100%;
        }

        /* ── Trial: Top sub-menu tabs (untuk Sales dulu, kalau bagus rollout ke semua) ── */
        .submenu-tabs {
            display: flex;
            align-items: center;
            gap: 16px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 8px 12px;
            margin: -0.5rem -0.5rem 1rem -0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            position: sticky;
            top: -1.5rem;
            z-index: 30;
        }
        .submenu-tabs-label {
            font-weight: 700;
            color: #1f2937;
            font-size: 13px;
            padding: 4px 12px 4px 4px;
            border-right: 1px solid #e5e7eb;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .submenu-tabs-list {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            flex: 1;
        }
        .submenu-tabs .tab {
            padding: 7px 14px;
            font-size: 13px;
            color: #4b5563;
            border-radius: 8px;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
            font-weight: 500;
            white-space: nowrap;
            line-height: 1.2;
        }
        .submenu-tabs .tab:hover {
            background: #f3f4f6;
            color: #111827;
        }
        .submenu-tabs .tab.active {
            background: #2563eb;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(37, 99, 235, 0.4);
        }

        /* ── Sub-tabs row (level-2): per-divisi di bawah Proses Produksi, dst.
              Pakai accent indigo supaya berbeda dari module tabs di atasnya. ── */
        .submenu-subtabs {
            margin-top: -0.5rem;
            padding: 6px 12px;
            top: auto;
            position: static;
            background: #f8fafc;
            border-color: #e5e7eb;
        }
        .submenu-subtabs .submenu-tabs-label {
            font-size: 12px;
            color: #4b5563;
            font-weight: 600;
        }
        .submenu-subtabs .tab {
            padding: 5px 12px;
            font-size: 12.5px;
        }
        .submenu-subtabs .tab.active {
            background: #4f46e5;
            box-shadow: 0 1px 3px rgba(79, 70, 229, 0.4);
        }

        @media (max-width: 700px) {
            .submenu-tabs { flex-direction: column; align-items: flex-start; gap: 8px; }
            .submenu-tabs-label { border-right: none; border-bottom: 1px solid #e5e7eb; padding: 0 0 6px 4px; width: 100%; }
        }

        /* Force children to be full width if using container */
        .content .container {
            max-width: 100% !important;
            padding-left: 0;
            padding-right: 0;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            border: none;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            color: white;
        }

        /* ══ ERP COMPACT TABLES (Enterprise Grade) ══ */
        table, .table {
            width: 100%;
            border-collapse: collapse;
        }

        table th, .table th {
            padding: 6px 8px;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background-color: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        table td, .table td {
            padding: 4px 8px;
            font-size: 12.5px;
            vertical-align: middle;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
        }

        table tbody tr:hover, .table tbody tr:hover {
            background-color: #f8fafc !important;
        }

        /* Spacing adjustment for better data density */
        .content {
            padding: 1.5rem;
        }

        .btn-success {
            background: #16a34a;
            color: white;
        }

        .btn-success:hover {
            background: #15803d;
        }

        .btn-danger {
            background: #dc2626;
            color: white;
        }

        .btn-danger:hover {
            background: #b91c1c;
        }

        .btn-warning {
            background: var(--accent);
            color: #000;
        }

        .btn-warning:hover {
            background: #ca8a04;
            color: #000;
        }

        .btn-outline {
            border: 1px solid #d1d5db;
            color: #374151;
            background: white;
        }

        .btn-outline:hover {
            background: #f9fafb;
        }
    </style>
</head>

<body>

    <div class="wrapper">

        <div class="sidebar">
            <button id="sidebarToggle" class="sidebar-toggle" type="button" title="Minimize sidebar" onclick="toggleSidebarCollapse()">‹</button>
            <div class="sidebar-header">
                <h3>NOUD ERP</h3>
                <div class="sidebar-brand-sub">ENTERPRISE SOLUTIONS</div>
            </div>

            <div class="sidebar-nav">
                @php
                    // Icon SVG (outline, currentColor) — diset sebagai variabel supaya rapi.
                    $svgDashboard  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12 12 3l9 9"/><path d="M5 10v10h5v-6h4v6h5V10"/></svg>';
                    $svgTask       = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3 7-7"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/></svg>';
                    $svgInventory  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8l-9-5-9 5v8l9 5 9-5z"/><path d="M3.3 7 12 12l8.7-5"/><path d="M12 22V12"/></svg>';
                    $svgSales      = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>';
                    $svgPurchasing = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.4"/><circle cx="19" cy="20" r="1.4"/><path d="M2 3h3l2.5 12.3a2 2 0 0 0 2 1.7h8.4a2 2 0 0 0 2-1.6L21.5 7H6.2"/></svg>';
                    $svgAsset      = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9h0M9 12h0M9 15h0M9 18h0"/></svg>';
                    $svgSDM        = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 20v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="3.5"/><path d="M22 20v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
                    $svgProduction = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 20a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8l-7 5V8l-7 5V8l-6 5z"/><path d="M6 18h0M11 18h0M16 18h0"/></svg>';
                    $svgCashBank   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9h0M18 15h0"/></svg>';
                    $svgAccounting = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8"/><path d="M8 11h0M12 11h0M16 11h0M8 14h0M12 14h0M8 17h0M12 17h0"/><path d="M16 14v3"/></svg>';
                    $svgReports    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="13" width="3" height="5"/><rect x="12" y="9" width="3" height="9"/><rect x="17" y="5" width="3" height="13"/></svg>';
                    $svgSettings   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1A2 2 0 1 1 4.4 17l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1A2 2 0 1 1 7 4.4l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1A2 2 0 1 1 19.7 7l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>';
                @endphp

                @if(should_show_menu_group('dashboard'))
                <a href="/erp/dashboard" class="menu-single {{ request()->is('erp/dashboard') ? 'active' : '' }}" data-tip="Dashboard">
                    <span class="menu-icon">{!! $svgDashboard !!}</span><span class="menu-label">&nbsp;Dashboard</span>
                </a>
                @endif

                {{-- ================= TASK MANAGER ================= --}}
                @if(should_show_menu_group('tasks'))
                <a href="{{ module_landing_url('tasks') }}" class="menu-single {{ request()->is('erp/tasks*') ? 'active' : '' }}" data-tip="Task Manager">
                    <span class="menu-icon">{!! $svgTask !!}</span><span class="menu-label">&nbsp;Task Manager</span>
                </a>
                @endif

                {{-- ================= INVENTORY ================= --}}
                @if(should_show_menu_group('inventory'))
                <a href="{{ module_landing_url('inventory') }}" class="menu-single {{ request()->is('erp/inventory/*') ? 'active' : '' }}" data-tip="Inventory">
                    <span class="menu-icon">{!! $svgInventory !!}</span><span class="menu-label">&nbsp;Inventory</span>
                </a>
                @endif

                {{-- ================= SALES ================= --}}
                @if(should_show_menu_group('sales'))
                @php $isSales = (request()->is('erp/sales/*') && !request()->routeIs('sales.reports.*')) || request()->is('erp/master/customers*'); @endphp
                <a href="{{ module_landing_url('sales') }}" class="menu-single {{ $isSales ? 'active' : '' }}" data-tip="Sales">
                    <span class="menu-icon">{!! $svgSales !!}</span><span class="menu-label">&nbsp;Sales</span>
                </a>
                @endif

                {{-- ================= POS ================= --}}
                @if(should_show_menu_group('pos'))
                <a href="{{ module_landing_url('pos') }}" class="menu-single {{ request()->is('erp/pos/*') ? 'active' : '' }}" data-tip="POS">
                    <span class="menu-icon">🧾</span><span class="menu-label">&nbsp;POS</span>
                </a>
                @endif

                {{-- ================= PURCHASING ================= --}}
                @if(should_show_menu_group('purchasing'))
                <a href="{{ module_landing_url('purchasing') }}" class="menu-single {{ request()->is('erp/purchasing/*') ? 'active' : '' }}" data-tip="Purchasing">
                    <span class="menu-icon">{!! $svgPurchasing !!}</span><span class="menu-label">&nbsp;Purchasing</span>
                </a>
                @endif

                {{-- ================= ASET TETAP ================= --}}
                @if(should_show_menu_group('fixed-assets'))
                <a href="{{ module_landing_url('fixed-assets') }}" class="menu-single {{ (request()->is('erp/fixed-assets/*') || request()->is('erp/fixed-assets')) ? 'active' : '' }}" data-tip="Aset Tetap">
                    <span class="menu-icon">{!! $svgAsset !!}</span><span class="menu-label">&nbsp;Aset Tetap</span>
                </a>
                @endif

                {{-- ================= SDM ================= --}}
                @if(should_show_menu_group('sdm'))
                @php $isSDM = request()->routeIs('sdm.*') || request()->routeIs('production.departments.*'); @endphp
                <a href="{{ module_landing_url('sdm') }}" class="menu-single {{ $isSDM ? 'active' : '' }}" data-tip="SDM">
                    <span class="menu-icon">{!! $svgSDM !!}</span><span class="menu-label">&nbsp;SDM</span>
                </a>
                @endif

                {{-- ================= PRODUCTION ================= --}}
                @if(should_show_menu_group('production'))
                @php $isProduction = request()->is('erp/production/*') && !($isSDM ?? false); @endphp
                <a href="{{ module_landing_url('production') }}" class="menu-single {{ $isProduction ? 'active' : '' }}" data-tip="Produksi">
                    <span class="menu-icon">{!! $svgProduction !!}</span><span class="menu-label">&nbsp;Produksi</span>
                </a>
                @endif

                {{-- ================= KAS & BANK ================= --}}
                @if(should_show_menu_group('cash-bank'))
                <a href="{{ module_landing_url('cash-bank') }}" class="menu-single {{ request()->is('erp/finance/cash-bank/*') ? 'active' : '' }}" data-tip="Kas & Bank">
                    <span class="menu-icon">{!! $svgCashBank !!}</span><span class="menu-label">&nbsp;Kas & Bank</span>
                </a>
                @endif

                {{-- ================= ACCOUNTING ================= --}}
                @if(should_show_menu_group('accounting'))
                @php $isAccounting = (request()->is('erp/accounting/*') || request()->is('erp/finance/accounts*')) && !(request()->routeIs('accounting.reports.*')); @endphp
                <a href="{{ module_landing_url('accounting') }}" class="menu-single {{ $isAccounting ? 'active' : '' }}" data-tip="Accounting">
                    <span class="menu-icon">{!! $svgAccounting !!}</span><span class="menu-label">&nbsp;Accounting</span>
                </a>
                @endif

                {{-- ================= LAPORAN ================= --}}
                @if(should_show_menu_group('reports'))
                @php $isLaporan = request()->routeIs('sales.reports.*') || request()->routeIs('accounting.reports.*'); @endphp
                <a href="{{ module_landing_url('reports') }}" class="menu-single {{ $isLaporan ? 'active' : '' }}" data-tip="Laporan">
                    <span class="menu-icon">{!! $svgReports !!}</span><span class="menu-label">&nbsp;Laporan</span>
                </a>
                @endif

                {{-- ================= SETTINGS ================= --}}
                @if(should_show_menu_group('settings'))
                <a href="{{ module_landing_url('settings') }}" class="menu-single {{ request()->is('erp/settings/*') ? 'active' : '' }}" data-tip="Pengaturan">
                    <span class="menu-icon">{!! $svgSettings !!}</span><span class="menu-label">&nbsp;Pengaturan</span>
                </a>
                @endif
            </div>

            @auth
            @php
                $authUser = auth()->user();
                $roleLabel = [
                    'super_admin' => 'Super Admin',
                    'admin'       => 'Admin',
                    'user'        => 'User',
                ][$authUser->role] ?? $authUser->role;
                $initial = strtoupper(substr($authUser->name ?? $authUser->username, 0, 1));
            @endphp
            <div class="user-profile"
                 x-data="{ open: false }"
                 :data-open="open"
                 @click.outside="open = false"
                 @keydown.escape.window="open = false">
                <div @click="open = !open" style="display:flex; align-items:center; gap:12px; width:100%;">
                    <div class="avatar">{{ $initial }}</div>
                    <div class="user-info">
                        <div class="user-name">{{ $authUser->name }}</div>
                        <div class="user-role">{{ $roleLabel }}</div>
                    </div>
                    <span class="chevron">▾</span>
                </div>

                <div class="user-menu-popup"
                     x-show="open"
                     x-cloak
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-100"
                     x-transition:leave-start="opacity-100 translate-y-0"
                     x-transition:leave-end="opacity-0 translate-y-1">
                    <div class="header">
                        <div class="name">{{ $authUser->name }}</div>
                        <div class="meta">&#64;{{ $authUser->username }}</div>
                        <span class="role-badge {{ $authUser->role }}">{{ $roleLabel }}</span>
                    </div>

                    @if($authUser->canManageUsers())
                        <a href="{{ route('settings.users.index') }}" class="item" style="text-decoration:none;">
                            <span class="icon">👥</span>
                            <span>Kelola User</span>
                        </a>
                        <div class="divider"></div>
                    @endif

                    <form method="POST" action="{{ route('logout') }}" style="margin:0;">
                        @csrf
                        <button type="submit" class="item danger">
                            <span class="icon">↪</span>
                            <span>Logout</span>
                        </button>
                    </form>
                </div>
            </div>
            @endauth

        </div>

        <div class="content">
            {{-- Top-tabs sub-menu untuk module dengan sub-menu (rendered conditional pada module saat ini) --}}
            @auth
                @php $currentModule = current_module(); @endphp
                @if($currentModule && $currentModule !== 'dashboard' && config("menu_permissions.$currentModule.children"))
                    @include('layouts.partials._top_tabs', ['module' => $currentModule])
                @endif
            @endauth

            @include('layouts.partials._flash')

            @yield('content')
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSubMenu(el) {
            el.classList.toggle('open');
            const items = el.nextElementSibling;
            if (items) items.classList.toggle('open');
        }

        function toggleMenu(el) {
            const parent = el.parentElement;
            parent.classList.toggle("active");

            // Save state to localStorage
            const module = parent.dataset.module;
            if (module) {
                localStorage.setItem('sidebar_' + module, parent.classList.contains("active"));
            }
        }

        function toggleSidebarCollapse() {
            const sidebar = document.querySelector('.sidebar');
            const collapsed = sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebar_collapsed', collapsed ? '1' : '0');
            const btn = document.getElementById('sidebarToggle');
            if (btn) btn.title = collapsed ? 'Expand sidebar' : 'Minimize sidebar';
        }

        // Initialize sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            // Restore collapsed state
            if (localStorage.getItem('sidebar_collapsed') === '1') {
                document.querySelector('.sidebar')?.classList.add('collapsed');
                const btn = document.getElementById('sidebarToggle');
                if (btn) btn.title = 'Expand sidebar';
            }

            // Tooltip position: set --tip-top di mouseenter untuk menu-single saat collapsed,
            // supaya tooltip (position:fixed) muncul di sebelah icon meski sidebar-nav scroll.
            document.querySelectorAll('.sidebar .menu-single[data-tip]').forEach(item => {
                item.addEventListener('mouseenter', () => {
                    const rect = item.getBoundingClientRect();
                    item.style.setProperty('--tip-top', rect.top + 'px');
                });
            });

            // Floating submenu via DOM portal (move ke <body> saat hover collapsed).
            // Reason: parent sidebar pakai position:sticky yang bikin stacking context terpisah,
            // jadi panel ke-clip oleh content page meski z-index tinggi. Portal lepas dari semua
            // ancestor stacking context.
            const sidebarEl = document.querySelector('.sidebar');
            sidebarEl?.querySelectorAll('.menu-group').forEach(group => {
                const tip = group.querySelector('.menu-title')?.dataset.tip;
                const items = group.querySelector('.menu-items');
                if (!items) return;

                // Inject header label sekali
                if (tip && !items.querySelector('.floating-header')) {
                    const h = document.createElement('div');
                    h.className = 'floating-header';
                    h.textContent = tip;
                    items.prepend(h);
                }

                // Save original DOM position untuk restoration
                items._originalParent = items.parentNode;
                items._originalNext = items.nextElementSibling;

                let hideTimer = null;

                const showPanel = () => {
                    if (!sidebarEl.classList.contains('collapsed')) return;
                    clearTimeout(hideTimer);
                    if (!items.classList.contains('floating-portal')) {
                        document.body.appendChild(items);
                        items.classList.add('floating-portal');
                    }
                    const rect = group.getBoundingClientRect();
                    items.style.left = (rect.right + 4) + 'px';
                    items.style.top = rect.top + 'px';
                    // Setelah render, clamp top supaya tidak keluar viewport bawah
                    requestAnimationFrame(() => {
                        const h = items.offsetHeight || 200;
                        const maxTop = window.innerHeight - h - 8;
                        items.style.top = Math.max(8, Math.min(rect.top, maxTop)) + 'px';
                    });
                };

                const restorePanel = () => {
                    if (!items.classList.contains('floating-portal')) return;
                    items.classList.remove('floating-portal');
                    items.style.left = '';
                    items.style.top = '';
                    if (items._originalNext && items._originalNext.parentNode === items._originalParent) {
                        items._originalParent.insertBefore(items, items._originalNext);
                    } else if (items._originalParent) {
                        items._originalParent.appendChild(items);
                    }
                };

                const scheduleHide = () => {
                    clearTimeout(hideTimer);
                    hideTimer = setTimeout(restorePanel, 180);
                };

                group.addEventListener('mouseenter', showPanel);
                group.addEventListener('mouseleave', scheduleHide);
                items.addEventListener('mouseenter', () => clearTimeout(hideTimer));
                items.addEventListener('mouseleave', scheduleHide);
            });

            // Saat sidebar di-expand, restore semua portal panels ke posisi semula
            document.getElementById('sidebarToggle')?.addEventListener('click', () => {
                document.querySelectorAll('.menu-items.floating-portal').forEach(panel => {
                    panel.classList.remove('floating-portal');
                    panel.style.left = '';
                    panel.style.top = '';
                    if (panel._originalNext && panel._originalNext.parentNode === panel._originalParent) {
                        panel._originalParent.insertBefore(panel, panel._originalNext);
                    } else if (panel._originalParent) {
                        panel._originalParent.appendChild(panel);
                    }
                });
            });

            document.querySelectorAll('.menu-group').forEach(group => {
                const module = group.dataset.module;
                const hasActiveChild = group.querySelector('a.active') !== null;

                if (hasActiveChild) {
                    // Always expand if current page is inside this group
                    group.classList.add('active');
                } else {
                    // Otherwise follow localStorage
                    const state = localStorage.getItem('sidebar_' + module);
                    if (state === "true") {
                        group.classList.add("active");
                    } else if (state === "false") {
                        group.classList.remove("active");
                    }
                }
            });

            // Persist sidebar scroll position lintas navigasi.
            // Save di scroll (debounced), restore saat load supaya menu yang user klik di bawah
            // tidak bikin sidebar reset ke atas.
            const sidebarNav = document.querySelector('.sidebar-nav');
            if (sidebarNav) {
                const saved = localStorage.getItem('sidebar_scroll_position');
                if (saved !== null) sidebarNav.scrollTop = parseInt(saved, 10) || 0;

                let scrollTimer;
                sidebarNav.addEventListener('scroll', () => {
                    clearTimeout(scrollTimer);
                    scrollTimer = setTimeout(() => {
                        localStorage.setItem('sidebar_scroll_position', sidebarNav.scrollTop);
                    }, 100);
                });
            }
        });
    </script>

    {{-- ============================================================
         FORMAT INPUT MATA UANG SERAGAM (global, semua modul)
         Pasang class "rupiah-input" pada <input type="text"> mata uang:
           - ketik → otomatis titik ribuan gaya Indonesia (1.000.000), tanpa desimal
           - submit (native) → titik di-strip → backend terima angka polos
           - delegation: berlaku utk input statis MAUPUN baris dinamis
         window.cleanNumber("1.000.000") → 1000000 (dipakai script AJAX).
         Backend tetap pakai helper clean_number() sbg jaring pengaman.
       ============================================================ --}}
    <script>
    (function () {
        function formatThousands(value) {
            var digits = String(value == null ? '' : value).replace(/\D/g, '');
            digits = digits.replace(/^0+(?=\d)/, ''); // buang leading zero
            if (digits === '') return '';
            return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        // Helper global: "1.000.000" / "Rp 1.000,5" → number
        window.cleanNumber = function (v) {
            if (v === null || v === undefined) return 0;
            var s = String(v).replace(/\./g, '').replace(/[^0-9,\-]/g, '').replace(',', '.');
            var n = parseFloat(s);
            return isNaN(n) ? 0 : n;
        };
        window.formatThousands = formatThousands;

        function formatAll(root) {
            (root || document).querySelectorAll('.rupiah-input').forEach(function (el) {
                if (el.value !== '') el.value = formatThousands(el.value);
            });
        }
        document.addEventListener('DOMContentLoaded', function () { formatAll(document); });

        // Format saat mengetik (input event, capture → kena elemen dinamis juga).
        document.addEventListener('input', function (e) {
            var el = e.target;
            if (!el || !el.classList || !el.classList.contains('rupiah-input')) return;
            var before = el.value.slice(0, el.selectionStart || 0);
            var digitsBefore = (before.match(/\d/g) || []).length;
            el.value = formatThousands(el.value);
            // posisikan kursor setelah digit ke-(digitsBefore)
            var pos = 0, seen = 0;
            while (pos < el.value.length && seen < digitsBefore) {
                if (/\d/.test(el.value.charAt(pos))) seen++;
                pos++;
            }
            try { el.setSelectionRange(pos, pos); } catch (_) {}
        }, true);

        // Strip titik sebelum submit native → backend terima angka polos (anti (int)"1.000"=1).
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || !form.querySelectorAll) return;
            form.querySelectorAll('.rupiah-input').forEach(function (input) {
                input.value = String(input.value).replace(/\./g, '');
            });
        }, true);
    })();
    </script>

    {{-- Alpine.js dipakai oleh sidebar user-profile dropdown + beberapa halaman --}}
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    @yield('scripts')
    @stack('scripts')
</body>

</html>
