<?php

namespace Tests\Feature;

use App\Models\GuestWorkspace;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class GuestWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_is_reachable_without_an_account(): void
    {
        $response = $this->get(route('landing'));

        $response->assertOk();
        $response->assertSee('Choose a sample dataset', false);
        $response->assertSee('View sample CSVs', false);
        $response->assertDontSee('Log in', false);
        $response->assertDontSee('Sign up', false);
    }

    public function test_starting_a_session_creates_a_workspace_and_shows_expiry(): void
    {
        $response = $this->post(route('try.start'), [
            'intent' => 'guided',
        ]);

        $response->assertRedirect(route('try.show'));

        $this->assertDatabaseCount('guest_workspaces', 1);

        $follow = $this->get(route('try.show'));
        $follow->assertOk();
        $follow->assertSee('Reconciliation workspace', false);
        $follow->assertSee('Time remaining', false);
        $follow->assertSee('Partner donations', false);
    }

    public function test_try_page_without_a_session_redirects_home(): void
    {
        $response = $this->get(route('try.show'));

        $response->assertRedirect(route('landing'));
    }

    public function test_a_second_session_cannot_open_another_workspace(): void
    {
        $this->post(route('try.start'), ['intent' => 'scenarios']);
        $firstWorkspace = GuestWorkspace::query()->firstOrFail();

        $this->flushSession();

        $response = $this->get(route('try.show'));
        $response->assertRedirect(route('landing'));

        $spoofed = $this->withSession([
            WorkspaceService::SESSION_SECRET_KEY => $firstWorkspace->id,
            'spoofed_workspace_id' => $firstWorkspace->id,
        ])->get(route('try.show').'?workspace='.$firstWorkspace->id);

        $spoofed->assertRedirect(route('landing'));
        $this->assertDatabaseCount('guest_workspaces', 1);
    }

    public function test_expired_workspace_returns_expired_page_and_json_410(): void
    {
        $this->post(route('try.start'), ['intent' => 'guided']);

        $workspace = GuestWorkspace::query()->firstOrFail();
        $workspace->forceFill([
            'expires_at' => now()->subMinute(),
        ])->save();

        $html = $this->get(route('try.show'));
        $html->assertStatus(410);
        $html->assertSee('Workspace expired', false);

        $json = $this->getJson(route('try.show'));
        $json->assertStatus(410);
        $json->assertJsonPath('error.code', 'WORKSPACE_EXPIRED');
    }

    public function test_reset_issues_a_new_workspace_and_invalidates_the_previous_secret(): void
    {
        $this->post(route('try.start'), ['intent' => 'scenarios']);

        $previousSecret = Session::get(WorkspaceService::SESSION_SECRET_KEY);
        $previousWorkspace = GuestWorkspace::query()->firstOrFail();

        $this->assertNotEmpty($previousSecret);

        $response = $this->post(route('try.reset'));
        $response->assertRedirect(route('try.show'));

        $newSecret = Session::get(WorkspaceService::SESSION_SECRET_KEY);
        $newWorkspace = GuestWorkspace::query()
            ->whereKeyNot($previousWorkspace->id)
            ->firstOrFail();

        $this->assertNotSame($previousSecret, $newSecret);
        $this->assertNotSame($previousWorkspace->id, $newWorkspace->id);

        $previousWorkspace->refresh();
        $this->assertTrue($previousWorkspace->isExpired());
        $this->assertSame(GuestWorkspace::STATE_EXPIRED, $previousWorkspace->state);

        $this->withSession([
            WorkspaceService::SESSION_SECRET_KEY => $previousSecret,
        ])->get(route('try.show'))->assertStatus(410);
    }
}
