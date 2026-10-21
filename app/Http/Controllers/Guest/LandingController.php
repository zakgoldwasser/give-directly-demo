<?php

namespace App\Http\Controllers\Guest;

use App\Domain\Reconciliation\Services\ReferenceFixtureCatalog;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

class LandingController extends Controller
{
    public function __invoke(ReferenceFixtureCatalog $fixtures): View
    {
        return view('landing.index', ['sampleScenarios' => $fixtures->choices()]);
    }
}
