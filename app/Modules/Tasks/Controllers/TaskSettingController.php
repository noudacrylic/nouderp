<?php

namespace App\Modules\Tasks\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Tasks\Models\TaskSetting;
use Illuminate\Http\Request;

class TaskSettingController extends Controller
{
    public function edit()
    {
        $setting = TaskSetting::current();
        $users = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        return view('erp.tasks.settings.edit', compact('setting', 'users'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'printing_default_user_id' => 'nullable|integer|exists:users,id',
        ]);
        $setting = TaskSetting::current();
        $setting->fill($data)->save();
        return back()->with('success', 'Pengaturan disimpan.');
    }
}
