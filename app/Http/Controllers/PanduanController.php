<?php

namespace App\Http\Controllers;

class PanduanController extends Controller
{
    /** Halaman Panduan in-app: panduan per peran/tugas, role-aware highlight. */
    public function index()
    {
        $categories = config('panduan', []);

        // Tandai kategori yang relevan dengan akses menu user (untuk badge "Untuk Anda").
        foreach ($categories as &$cat) {
            $relevant = false;
            foreach ($cat['menus'] ?? [] as $menuKey) {
                if (user_can_access($menuKey)) {
                    $relevant = true;
                    break;
                }
            }
            $cat['relevant'] = $relevant;
        }
        unset($cat);

        return view('erp.panduan.index', compact('categories'));
    }
}
