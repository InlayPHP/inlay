<?php

namespace App\Http\Controllers;

use App\Imports\UserImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inlay\Imports\ImportValidator;

class UserDemoController extends Controller
{
    public function importPreview(Request $request, ImportValidator $validator): JsonResponse
    {
        $payload = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*' => ['array'],
            'mapping' => ['sometimes', 'array'],
        ]);

        return response()->json($validator->preview(
            new UserImporter,
            $payload['rows'],
            $payload['mapping'] ?? [],
        ));
    }
}
