{{--
    Generic top-tabs partial.
    Param:
      $module — slug module (mis. 'sales', 'inventory', 'production', ...)

    Render label + tabs untuk semua sub-menu yang user-accessible.
    Special case: production.process — render single tab ke first accessible dept.
--}}
@php
    $moduleCfg = config("menu_permissions.$module");
    $children = $moduleCfg['children'] ?? [];
@endphp

@if(!empty($children))
@php
    $currentRouteName = \Illuminate\Support\Facades\Route::currentRouteName();
@endphp
<div class="submenu-tabs" data-module="{{ $module }}">
    <div class="submenu-tabs-label">{{ $moduleCfg['icon'] ?? '' }} {{ $moduleCfg['label'] ?? ucfirst($module) }}</div>
    <div class="submenu-tabs-list">
        @foreach($children as $key => $cfg)
            @php
                // role_gate per child (mis. settings.users hanya super_admin/admin)
                $allowedByRole = true;
                if (isset($cfg['role_gate'])) {
                    $u = auth()->user();
                    $allowedByRole = $u && in_array($u->role, $cfg['role_gate'], true);
                }

                // Special: production.process — link ke first accessible dept (atau umbrella)
                $url = $cfg['url'] ?? '#';
                $accessible = user_can_access($key);
                if (!empty($cfg['dynamic_dept']) && $module === 'production') {
                    $hasUmbrella = auth()->user()?->hasMenuPermission('production.process') ?? false;
                    $isAdminLike = in_array(auth()->user()?->role, ['super_admin','admin'], true);
                    $accessibleDept = null;
                    foreach (app(\App\Services\MenuRegistry::class)->productionDepartments() as $d) {
                        if ($isAdminLike || $hasUmbrella || (auth()->user()?->hasMenuPermission("production.process.{$d->id}"))) {
                            $accessibleDept = $d;
                            break;
                        }
                    }
                    if ($accessibleDept) {
                        $url = url("/erp/production/process?department_id={$accessibleDept->id}");
                    } else {
                        $accessible = false;
                    }
                }

                // Active state berdasarkan route_patterns
                $isActive = false;
                if ($currentRouteName) {
                    foreach ($cfg['route_patterns'] ?? [] as $pattern) {
                        if (\Illuminate\Support\Str::is($pattern, $currentRouteName)) {
                            $isActive = true;
                            break;
                        }
                    }
                }
            @endphp
            @if($allowedByRole && $accessible)
                <a href="{{ $url }}" class="tab {{ $isActive ? 'active' : '' }}">
                    {{ $cfg['label'] }}
                </a>
            @endif
        @endforeach
    </div>
</div>

{{-- Sub-tabs generik (tier-2): kalau top-tab aktif punya 'subtabs' di config (gaya Absensi).
     Mis. Kas & Bank → Pengeluaran → [Umum | Bayar Ongkir | Refund | Gaji | Biaya API]. --}}
@php
    $activeChild = null; $activeChildKey = null;
    foreach ($children as $ck => $ccfg) {
        if (empty($ccfg['subtabs']) || !$currentRouteName) continue;
        foreach ($ccfg['route_patterns'] ?? [] as $p) {
            if (\Illuminate\Support\Str::is($p, $currentRouteName)) { $activeChild = $ccfg; $activeChildKey = $ck; break 2; }
        }
    }
@endphp
@if($activeChild && user_can_access($activeChildKey))
    <div class="submenu-tabs submenu-subtabs" data-module="{{ $module }}">
        <div class="submenu-tabs-label">{{ $activeChild['label'] }}</div>
        <div class="submenu-tabs-list">
            @foreach($activeChild['subtabs'] as $st)
                @php
                    $stActive = false;
                    foreach ($st['route_patterns'] ?? [] as $p) {
                        if (\Illuminate\Support\Str::is($p, $currentRouteName)) { $stActive = true; break; }
                    }
                @endphp
                <a href="{{ $st['url'] }}" class="tab {{ $stActive ? 'active' : '' }}">{{ $st['label'] }}</a>
            @endforeach
        </div>
    </div>
@endif

{{-- Sub-tabs: per-divisi untuk Proses Produksi & Eksekutor (mirip sub-tabs di Absensi) --}}
@php
    $isProcessRoute   = $currentRouteName && \Illuminate\Support\Str::is('production.process.*', $currentRouteName);
    $isExecutorsRoute = $currentRouteName && \Illuminate\Support\Str::is('production.executors.*', $currentRouteName);
@endphp
@if($module === 'production' && ($isProcessRoute || $isExecutorsRoute))
    @php
        $isAdminLikeDept = in_array(auth()->user()?->role, ['super_admin','admin'], true);
        $accessibleDepts = collect();

        if ($isProcessRoute) {
            // Proses Produksi: gate per-divisi (production.process.{id}).
            $hasUmbrellaProcess = auth()->user()?->hasMenuPermission('production.process') ?? false;
            foreach (app(\App\Services\MenuRegistry::class)->productionDepartments() as $d) {
                if ($isAdminLikeDept || $hasUmbrellaProcess || (auth()->user()?->hasMenuPermission("production.process.{$d->id}"))) {
                    $accessibleDepts->push($d);
                }
            }
            $subtabBase = '/erp/production/process';
        } else {
            // Eksekutor: gate tunggal (production.executors) — tampilkan semua divisi.
            $canExecutors = $isAdminLikeDept || (auth()->user()?->hasMenuPermission('production.executors') ?? false);
            if ($canExecutors) {
                $accessibleDepts = collect(app(\App\Services\MenuRegistry::class)->productionDepartments());
            }
            $subtabBase = '/erp/production/executors';
        }

        $activeDeptId = request('department_id');
    @endphp
    @if($accessibleDepts->isNotEmpty())
        <div class="submenu-tabs submenu-subtabs">
            <div class="submenu-tabs-label">Divisi</div>
            <div class="submenu-tabs-list">
                @foreach($accessibleDepts as $d)
                    <a href="{{ url($subtabBase . '?department_id=' . $d->id) }}"
                       class="tab {{ (string)$activeDeptId === (string)$d->id ? 'active' : '' }}">
                        {{ $d->name }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif
@endif
@endif
