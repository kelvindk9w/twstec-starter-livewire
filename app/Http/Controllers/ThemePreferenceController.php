<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Preferência de tema do usuário logado (claro/escuro/sistema).
 *
 * O dispositivo resolve na hora via localStorage (sem flash — ver
 * partials/theme-script); esta rota só persiste o padrão da CONTA, usado
 * como data-theme-default em outros dispositivos/sessões.
 */
final class ThemePreferenceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme' => ['required', Rule::in(['light', 'dark', 'system'])],
        ]);

        $request->user()->forceFill(['theme' => $validated['theme']])->save();

        return response()->json(['theme' => $validated['theme']]);
    }
}
