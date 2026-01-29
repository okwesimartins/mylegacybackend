<?php

namespace App\Http\Controllers;

use App\Models\AppUpdateConfig;
use Illuminate\Http\Request;

class AppUpdateConfigController extends Controller
{
    // POST: create/update config by type (android/ios)
    public function upsert(Request $request)
    {
        $data = $request->validate([
            'type'           => ['required', 'string', 'max:50'],
            'currentVersion' => ['required', 'string', 'max:30'],
            'minimumVersion' => ['required', 'string', 'max:30'],
            'forceUpdate'    => ['required', 'boolean'],
            'updateMessage'  => ['nullable', 'string'],
            'storeUrl'       => ['nullable', 'url'],
            'releaseDate'    => ['nullable', 'date'],
        ]);

        $record = AppUpdateConfig::updateOrCreate(
            ['type' => $data['type']],
            [
                'current_version' => $data['currentVersion'],
                'minimum_version' => $data['minimumVersion'],
                'force_update'    => $data['forceUpdate'],
                'update_message'  => $data['updateMessage'] ?? null,
                'store_url'       => $data['storeUrl'] ?? null,
                'release_date'    => $data['releaseDate'] ?? null,
            ]
        );

        return response()->json($record, 200);
    }

    // GET: fetch config by type
    public function getByType(string $type)
    {
        $record = AppUpdateConfig::where('type', $type)->first();

        if (!$record) {
            return response()->json(['message' => 'Config not found'], 404);
        }

        return response()->json($record, 200);
    }
}
