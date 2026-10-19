<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\ExportBatch;
use App\Models\GuestWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportDownloadController extends Controller
{
    public function __invoke(Request $request, ExportBatch $export): StreamedResponse
    {
        /** @var GuestWorkspace $workspace */
        $workspace = $request->attributes->get('guest_workspace');

        abort_unless($export->guest_workspace_id === $workspace->id, 404);
        abort_unless(Storage::disk('exports')->exists($export->private_file_path), 404);

        if ($export->downloaded_at === null) {
            $export->forceFill([
                'status' => $export->status === ExportBatch::STATUS_CHECKED
                    ? ExportBatch::STATUS_CHECKED
                    : ExportBatch::STATUS_DOWNLOADED,
                'downloaded_at' => now(),
            ])->save();
        }

        return Storage::disk('exports')->download(
            $export->private_file_path,
            "reconciliation-{$export->id}.zip",
            ['Content-Type' => 'application/zip'],
        );
    }
}
