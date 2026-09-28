<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Continuous deployment (A-25): a push to master reaches production only
 * through a SUCCESSFUL CI run of that push, as exactly the commit CI tested.
 * Each assertion below is a way the pipeline could otherwise ship something
 * nobody tested — a red build, a pull request, another branch, a fork, or a
 * newer untested tip of master — so none of them may silently disappear.
 */
final class DeployWorkflowTest extends TestCase
{
    private string $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        // Normalised: the repository stores LF, a Windows checkout may hold CRLF.
        $this->workflow = str_replace("\r\n", "\n", (string) file_get_contents(base_path('.github/workflows/deploy.yml')));
    }

    #[Test]
    public function the_pipeline_starts_by_hand_or_after_ci_completes_on_master(): void
    {
        $this->assertMatchesRegularExpression('/^\s{2}workflow_dispatch:/m', $this->workflow, 'the manual Run workflow button disappeared');
        $this->assertMatchesRegularExpression(
            '/workflow_run:\s*\n\s+workflows: \[CI\]\s*\n\s+types: \[completed\]\s*\n\s+branches: \[master\]/',
            $this->workflow,
            'the automatic trigger must be the completion of the CI workflow on master',
        );
        $this->assertDoesNotMatchRegularExpression('/^\s{2}push:/m', $this->workflow, 'a raw push trigger would deploy before CI has passed');
    }

    #[Test]
    public function an_automatic_run_builds_only_a_successful_ci_run_of_a_push_to_master_in_this_repository(): void
    {
        foreach ([
            "github.event.workflow_run.conclusion == 'success'" => 'a failed or cancelled CI run would deploy',
            "github.event.workflow_run.event == 'push'" => 'a pull request CI run would deploy',
            "github.event.workflow_run.head_branch == 'master'" => 'another branch would deploy',
            'github.event.workflow_run.head_repository.full_name == github.repository' => "a fork's CI run would deploy",
            'github.event.workflow_run.head_sha == github.sha' => "re-running an older commit's CI would roll production back over a newer release and its migrated schema",
        ] as $condition => $risk) {
            $this->assertStringContainsString($condition, $this->workflow, $risk);
        }
    }

    #[Test]
    public function the_release_is_the_commit_ci_tested_not_the_tip_of_master(): void
    {
        $this->assertStringContainsString(
            'ref: ${{ github.event.workflow_run.head_sha || github.sha }}',
            $this->workflow,
            'an automatic run must check out the tested commit',
        );
        $this->assertStringContainsString('commit="$(git rev-parse HEAD)"', $this->workflow, 'the release must be named after the checked-out commit');
        $this->assertStringNotContainsString('${GITHUB_SHA::12}', $this->workflow, 'GITHUB_SHA is the tip of master on an automatic run, not the tested commit');
    }

    #[Test]
    public function releases_never_overlap_and_the_host_key_stays_pinned(): void
    {
        // The queue belongs to the deploy job: at workflow level every skipped run (red CI,
        // stale commit, pull request) would join it and cancel a waiting green deploy.
        $this->assertMatchesRegularExpression(
            '/^  deploy:\n(?:    .*\n)*?    concurrency:\n      group: deploy\n      cancel-in-progress: false$/m',
            $this->workflow,
            'the deploy job must serialise releases without cancelling one',
        );
        $this->assertDoesNotMatchRegularExpression('/^concurrency:/m', $this->workflow, 'a workflow-level concurrency group lets skipped runs cancel a pending deploy');
        $this->assertStringContainsString('DEPLOY_SSH_KNOWN_HOSTS is not set', $this->workflow, 'the deploy job must keep failing closed without a pinned host key');
        $this->assertStringContainsString('RELEASE_NOTE: ${{ inputs.release_note || github.event.workflow_run.head_commit.message }}', $this->workflow);
        $this->assertStringNotContainsString('echo "note: ${{', $this->workflow, 'a commit message must reach the shell through env, never interpolated into the script');
    }
}
