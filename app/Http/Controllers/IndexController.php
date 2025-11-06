<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class IndexController extends Controller
{
    public function index()
    {
        return redirect()->route('documents.index');
    }

    public function show()
    {
        return Inertia::render('Index/Show');
    }
}
