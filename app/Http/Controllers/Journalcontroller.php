<?php

namespace App\Http\Controllers;

use App\Models\Journals;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use JWTAuth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class JournalController extends Controller
{
    /**
     * Create or Update a journal
     */
public function saveJournal(Request $request)
{
    $user = JWTAuth::parseToken()->authenticate();
    $userId = $user->id;

    $validator = Validator::make($request->all(), [
        'id' => 'nullable|integer|exists:journals,id',
        'name' => 'required|string|max:255',
        'text' => 'nullable|string',
        'audio' => 'nullable|file|mimes:mp3,wav,ogg|max:10240', // 10MB
        'date' => 'nullable|date',
    ]);

    if ($validator->fails()) {
        return response()->json(['status'=>422,'errors'=>$validator->errors()], 422);
    }

    $encryptedText = $request->text ? Crypt::encryptString($request->text) : null;

    $journal = Journals::updateOrCreate(
        ['id' => $request->id ?? 0, 'user_id' => $userId],
        [
            'name' => Crypt::encryptString($request->name),
            'text' => $encryptedText,
            'date' => $request->date ?? now(),
        ]
    );

    // Handle audio upload
    if ($request->hasFile('audio')) {
        $file = $request->file('audio');
        $ext = $file->getClientOriginalExtension();
        $filename = uniqid('audio_') . '.' . $ext;

        // Move file to public/audio folder first
        $destinationPath = public_path('audio');
        if (!file_exists($destinationPath)) {
            mkdir($destinationPath, 0755, true);
        }
        $file->move($destinationPath, $filename);

        // Encrypt the moved file contents
        $filePath = $destinationPath . '/' . $filename;
        $content = file_get_contents($filePath);
        $encryptedContent = Crypt::encrypt($content);
        file_put_contents($filePath, $encryptedContent);

        $journal->audio = $filename;
        $journal->save();
    }

    return response()->json([
        'status' => 200,
        'message' => 'Journal saved successfully',
        'journal_id' => $journal->id
    ]);
}

public function getJournals()
{
    $user = JWTAuth::parseToken()->authenticate();
    $userId = $user->id;

    $journals = Journals::where('user_id', $userId)
        ->orderByDesc('date')
        ->get();

    $result = $journals->map(function($j) {
        $audioUrl = null;
        if ($j->audio) {
            $filePath = public_path('audio/' . $j->audio);
            if (file_exists($filePath)) {
                // Decrypt file content
                $decryptedContent = Crypt::decrypt(file_get_contents($filePath));
                file_put_contents($filePath, $decryptedContent); // overwrite encrypted file temporarily
                $audioUrl = 'https://mylegacyjournals.app/backend/public/audio/' . $j->audio;
            }
        }

        return [
            'id' => $j->id,
            'name' => $j->name ? Crypt::decryptString($j->name) : null,
            'text' => $j->text ? Crypt::decryptString($j->text) : null,
            'date' => $j->date,
            'audio_url' => $audioUrl,
        ];
    });

    return response()->json(['status'=>200,'data'=>$result]);
}

}
