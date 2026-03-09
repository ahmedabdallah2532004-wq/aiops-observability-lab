<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AIOpsController extends Controller
{
    public function normal()
    {
        return response()->json(['status' => 'success', 'message' => 'Normal endpoint']);
    }

    public function slow(Request $request)
    {
        if ($request->has('hard') && $request->get('hard') == '1') {
            sleep(rand(5, 7)); // hard timeout simulation
            return response()->json(['status' => 'success', 'message' => 'Hard slow endpoint']);
        }
        
        usleep(rand(100, 500) * 1000); // 100-500ms
        return response()->json(['status' => 'success', 'message' => 'Slow endpoint']);
    }

    public function error()
    {
        abort(500, 'Simulated system error');
    }

    public function random()
    {
        if (rand(1, 100) <= 20) {
            abort(500, 'Random error');
        }
        return response()->json(['status' => 'success', 'message' => 'Random endpoint']);
    }

    public function db(Request $request)
    {
        if ($request->has('fail') && $request->get('fail') == '1') {
            DB::statement('SELECT * FROM non_existent_table_for_aiops_lab');
        }
        
        DB::statement('SELECT 1');
        return response()->json(['status' => 'success', 'message' => 'DB query successful']);
    }

    public function validateData(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'age' => 'required|integer|between:18,60',
        ]);
        
        return response()->json(['status' => 'success', 'message' => 'Validation passed']);
    }
}
