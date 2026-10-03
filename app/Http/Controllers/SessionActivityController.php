<?php

namespace App\Http\Controllers;

use App\Support\SessionIdle;
use Illuminate\Http\Request;

class SessionActivityController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(SessionIdle::status($request))->header('Cache-Control', 'no-store');
    }

    public function store(Request $request)
    {
        SessionIdle::touch($request);

        return $this->show($request);
    }
}
