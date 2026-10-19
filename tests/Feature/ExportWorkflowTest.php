<?php

namespace Tests\Feature;

use App\Models\ExportBatch;
use App\Models\ReconciliationApproval;
use App\Models\ReconciliationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ExportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('imports');
        Storage::fake('exports');
        config(['reconciliation.allow_arbitrary_uploads' => false]);
    }

    public function test_approved_actions_produce_an_idempotent_private_export_package(): void
    {
        $run = $this->completeGuidedReview();

        $this->get(route('try.show'))
            ->assertOk()
            ->assertSee('Review the proposed destination changes', false)
            ->assertSee('Approve 6 eligible changes', false);

        $this->post(route('try.approvals.store'), ['run_id' => $run->id])
            ->assertRedirect(route('try.show'));
        $this->post(route('try.approvals.store'), ['run_id' => $run->id])
            ->assertRedirect(route('try.show'));

        $this->assertDatabaseCount('reconciliation_approvals', 6);
        $this->assertSame(
            ['D002', 'D003', 'D006', 'D007', 'D008', 'D010'],
            ReconciliationApproval::query()->orderBy('transaction_id')->pluck('transaction_id')->all(),
        );

        $this->post(route('try.exports.store'), ['run_id' => $run->id])
            ->assertRedirect(route('try.show'));

        $batch = ExportBatch::query()->sole();
        $this->assertEquals([
            'creates' => 2,
            'updates' => 4,
            'unchanged' => 2,
            'held' => 4,
            'approved_actions' => 6,
        ], $batch->counts);
        Storage::disk('exports')->assertExists($batch->private_file_path);

        $firstBytes = Storage::disk('exports')->get($batch->private_file_path);
        $files = $this->readZip($batch);
        $this->assertSame(
            ['audit.csv', 'creates.csv', 'held.csv', 'manifest.json', 'updates.csv'],
            array_keys($files),
        );
        $this->assertStringContainsString("\n", $files['creates.csv']);
        $this->assertStringContainsString('D002', $files['creates.csv']);
        $this->assertStringContainsString('D006', $files['creates.csv']);
        $this->assertStringContainsString('D003', $files['updates.csv']);
        $this->assertStringContainsString('D007', $files['updates.csv']);
        $this->assertStringContainsString('D004', $files['held.csv']);
        $this->assertStringContainsString('D012', $files['held.csv']);
        $this->assertStringNotContainsString('@example.test', implode('', $files));
        $this->assertSame(3, substr_count($files['creates.csv'], "\n"));
        $this->assertSame(5, substr_count($files['updates.csv'], "\n"));
        $this->assertSame(5, substr_count($files['held.csv'], "\n"));
        $this->assertSame(9, substr_count($files['audit.csv'], "\n"));

        $manifest = json_decode($files['manifest.json'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($batch->id, $manifest['batch_id']);
        $this->assertSame($run->id, $manifest['run']['id']);
        $this->assertSame('generic_gift_v1', $manifest['profiles']['export']);
        $this->assertCount(4, $manifest['updates']);

        foreach (['creates.csv', 'updates.csv', 'held.csv', 'audit.csv'] as $filename) {
            $this->assertSame(hash('sha256', $files[$filename]), $manifest['files'][$filename]['sha256']);
        }

        $this->post(route('try.exports.store'), ['run_id' => $run->id])
            ->assertRedirect(route('try.show'));

        $this->assertDatabaseCount('export_batches', 1);
        $this->assertSame($firstBytes, Storage::disk('exports')->get($batch->private_file_path));

        $this->get(route('try.show'))
            ->assertOk()
            ->assertSee('2 creates · 4 updates · 4 held exclusions', false)
            ->assertSee('Download ZIP', false);

        $this->get(route('try.exports.download', $batch))
            ->assertOk()
            ->assertDownload("reconciliation-{$batch->id}.zip");
        $this->assertNotNull($batch->refresh()->downloaded_at);
    }

    public function test_export_requires_current_run_approvals_and_changed_review_evidence_invalidates_them(): void
    {
        $run = $this->completeGuidedReview();

        $this->from(route('try.show'))
            ->post(route('try.exports.store'), ['run_id' => $run->id])
            ->assertRedirect(route('try.show'))
            ->assertSessionHasErrors('export');

        $this->post(route('try.approvals.store'), ['run_id' => $run->id]);
        $this->post(route('try.review.campaigns.store'), [
            'campaign_code' => 'NEW-PROGRAM',
            'campaign_id' => 'CAMPAIGN-REVISED',
        ]);
        $currentRun = ReconciliationRun::query()->orderByDesc('sequence')->firstOrFail();

        $this->assertNotSame($run->id, $currentRun->id);
        $this->assertSame(0, $currentRun->approvals()->count());

        $this->from(route('try.show'))
            ->post(route('try.exports.store'), ['run_id' => $run->id])
            ->assertRedirect(route('try.show'))
            ->assertSessionHasErrors('run_id');

        $this->assertDatabaseCount('export_batches', 0);
    }

    public function test_a_second_workspace_cannot_download_the_first_workspace_export(): void
    {
        $run = $this->completeGuidedReview();
        $this->post(route('try.approvals.store'), ['run_id' => $run->id]);
        $this->post(route('try.exports.store'), ['run_id' => $run->id]);
        $batch = ExportBatch::query()->sole();

        $this->post(route('try.start'), ['intent' => 'scenarios']);

        $this->get(route('try.exports.download', $batch))->assertNotFound();
    }

    private function completeGuidedReview(): ReconciliationRun
    {
        $this->post(route('try.start'), ['intent' => 'guided']);
        $this->post(route('try.review.campaigns.store'), [
            'campaign_code' => 'NEW-PROGRAM',
            'campaign_id' => 'CAMPAIGN-NEW-PROGRAM',
        ]);
        $this->post(route('try.review.matches.store'), [
            'transaction_id' => 'D007',
            'crm_record_id' => 'CRM005',
        ]);

        return ReconciliationRun::query()->orderByDesc('sequence')->firstOrFail();
    }

    /** @return array<string, string> */
    private function readZip(ExportBatch $batch): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('exports')->path($batch->private_file_path)));
        $files = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $contents = $zip->getFromIndex($index);
            $this->assertIsString($name);
            $this->assertIsString($contents);
            $files[$name] = $contents;
        }

        $zip->close();
        ksort($files, SORT_STRING);

        return $files;
    }
}
