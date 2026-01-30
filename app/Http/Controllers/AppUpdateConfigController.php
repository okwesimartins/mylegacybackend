<?php

namespace App\Http\Controllers;

use App\Models\AppUpdateConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AppUpdateConfigController extends Controller
{
    // POST: create/update config by type (android/ios)
    public function upsert(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type'           => 'required|string|max:150',
            'currentVersion' => 'required|string|max:150',
            'minimumVersion' => 'required|string|max:150',
            'forceUpdate'    => 'required|boolean',
            'updateMessage'  => 'nullable|string',
            'storeUrl'       => 'nullable|string',
            'releaseDate'    => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // ✅ This is your array of validated data
        $data = $validator->validated();

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
