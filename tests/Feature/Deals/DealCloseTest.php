<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Enums\AccountType;
use App\Enums\ActivityLogEvent;
use App\Enums\CloseReasonKind;
use App\Enums\DealStatus;
use App\Enums\StageKind;
use App\Exceptions\Deals\InvalidDealTransitionException;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\DealStageLog;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Services\Deals\DealCloseService;
use App\Services\Deals\DealStageWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Winning, losing and reopening deals (decisions D-6, D-8): a close needs a
 * reason of the matching kind, a win promotes the prospect account, a closed
 * deal is frozen until it is reopened.
 */
final class DealCloseTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    #[Test]
    public function winning_requires_a_close_reason_of_the_won_kind(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $wonStage = $this->stageOfKind($deal, StageKind::Won);

        try {
            app(DealStageWorkflow::class)->transition($deal, $wonStage, $rep);
            $this->fail('A win without a reason was accepted.');
        } catch (InvalidDealTransitionException $exception) {
            $this->assertSame(__('deals.validation.close_reason_required'), $exception->getMessage());
        }

        try {
            app(DealCloseService::class)->win($deal, $this->reasonOfKind(CloseReasonKind::Lost), $rep);
            $this->fail('A win with a loss reason was accepted.');
        } catch (InvalidDealTransitionException $exception) {
            $this->assertSame(__('deals.validation.close_reason_kind_mismatch'), $exception->getMessage());
        }

        $deal->refresh();
        $this->assertSame(DealStatus::Open, $deal->status);
        $this->assertNull($deal->won_at);
        $this->assertNull($deal->close_reason_id);
        $this->assertSame(0, DealStageLog::query()->where('deal_id', $deal->getKey())->count());
    }

    #[Test]
    public function winning_stamps_the_deal_and_audits_the_win(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'amount' => 12500.50]);
        $reason = $this->reasonOfKind(CloseReasonKind::Won);
        $wonStage = $this->stageOfKind($deal, StageKind::Won);

        app(DealCloseService::class)->win($deal, $reason, $rep, 'Signed today');

        $this->assertSame(DealStatus::Won, $deal->status);
        $this->assertTrue($deal->isWon());
        $this->assertTrue($deal->isClosed());

        $deal->refresh();
        $this->assertSame(DealStatus::Won, $deal->status);
        $this->assertSame($wonStage->getKey(), $deal->stage_id);
        $this->assertNotNull($deal->won_at);
        $this->assertNull($deal->lost_at);
        $this->assertSame($reason->getKey(), $deal->close_reason_id);
        $this->assertSame(100, $deal->effective_probability);

        $log = DealStageLog::query()->where('deal_id', $deal->getKey())->firstOrFail();
        $this->assertSame($wonStage->getKey(), (int) $log->to_stage_id);
        $this->assertSame('Signed today', $log->notes);

        $audit = ActivityLog::query()->where('description', ActivityLogEvent::DealWon->value)->latest('id')->firstOrFail();
        $this->assertSame($deal->getKey(), (int) $audit->subject_id);
        $this->assertSame('12500.50', $audit->properties->get('amount'));
        $this->assertSame($deal->currency, $audit->properties->get('currency'));
        $this->assertSame($reason->display_name, $audit->properties->get('close_reason'));
        $this->assertSame($deal->title, $audit->properties->get('subject_label'));
    }

    #[Test]
    public function winning_promotes_a_prospect_account_to_customer(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['type' => AccountType::Prospect]);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);

        app(DealCloseService::class)->win($deal, $this->reasonOfKind(CloseReasonKind::Won), $rep);

        $account->refresh();
        $this->assertSame(AccountType::Customer, $account->type);
        $this->assertTrue($account->isCustomer());
        $this->assertNotNull($account->customer_since);
        $this->assertTrue($account->customer_since->isSameDay(today()));

        $audit = ActivityLog::query()->where('description', ActivityLogEvent::AccountBecameCustomer->value)->latest('id')->firstOrFail();
        $this->assertSame($account->getKey(), (int) $audit->subject_id);
        $this->assertSame($rep->getKey(), (int) $audit->causer_id);
        $this->assertSame($deal->getKey(), (int) $audit->properties->get('deal_id'));
        $this->assertSame($deal->title, $audit->properties->get('deal_title'));
        $this->assertSame($account->name, $audit->properties->get('subject_label'));
    }

    #[Test]
    public function winning_leaves_customers_and_partners_untouched_and_works_without_an_account(): void
    {
        $rep = $this->salesRep();
        $reason = $this->reasonOfKind(CloseReasonKind::Won);

        $customer = Account::factory()->customer()->create(['customer_since' => '2024-01-15']);
        $customerDeal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $customer->getKey()]);
        app(DealCloseService::class)->win($customerDeal, $reason, $rep);

        $customer->refresh();
        $this->assertSame(AccountType::Customer, $customer->type);
        $this->assertSame('2024-01-15', $customer->customer_since?->toDateString());

        $partner = Account::factory()->create(['type' => AccountType::Partner]);
        $partnerDeal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $partner->getKey()]);
        app(DealCloseService::class)->win($partnerDeal, $reason, $rep);

        $partner->refresh();
        $this->assertSame(AccountType::Partner, $partner->type);
        $this->assertNull($partner->customer_since);

        $this->assertSame(0, ActivityLog::query()->where('description', ActivityLogEvent::AccountBecameCustomer->value)->count());

        $orphan = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => null]);
        app(DealCloseService::class)->win($orphan, $reason, $rep);

        $this->assertSame(DealStatus::Won, $orphan->refresh()->status);
        $this->assertSame(3, ActivityLog::query()->where('description', ActivityLogEvent::DealWon->value)->count());
    }

    #[Test]
    public function losing_stamps_the_deal_and_audits_the_loss(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $reason = $this->reasonOfKind(CloseReasonKind::Lost);
        $lostStage = $this->stageOfKind($deal, StageKind::Lost);

        app(DealCloseService::class)->lose($deal, $reason, $rep, 'Went with a cheaper vendor.');

        $deal->refresh();
        $this->assertSame(DealStatus::Lost, $deal->status);
        $this->assertTrue($deal->isLost());
        $this->assertSame($lostStage->getKey(), $deal->stage_id);
        $this->assertNotNull($deal->lost_at);
        $this->assertNull($deal->won_at);
        $this->assertSame($reason->getKey(), $deal->close_reason_id);
        $this->assertSame('Went with a cheaper vendor.', $deal->lost_notes);
        $this->assertSame(0, $deal->effective_probability);

        $audit = ActivityLog::query()->where('description', ActivityLogEvent::DealLost->value)->latest('id')->firstOrFail();
        $this->assertSame($deal->getKey(), (int) $audit->subject_id);
        $this->assertSame($reason->display_name, $audit->properties->get('close_reason'));
        $this->assertSame('Went with a cheaper vendor.', $audit->properties->get('lost_notes'));
        $this->assertSame($lostStage->display_name, $audit->properties->get('to_stage'));
    }

    #[Test]
    public function losing_with_a_won_reason_is_refused(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);

        $this->expectException(InvalidDealTransitionException::class);
        app(DealCloseService::class)->lose($deal, $this->reasonOfKind(CloseReasonKind::Won), $rep);
    }

    #[Test]
    public function a_closed_deal_is_frozen_until_it_is_reopened(): void
    {
        $rep = $this->salesRep();
        $readOnly = $this->readOnly();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);

        $this->assertTrue($rep->can('update', $deal));
        $this->assertTrue($rep->can('changeStage', $deal));
        $this->assertTrue($rep->can('close', $deal));
        $this->assertFalse($rep->can('reopen', $deal));

        app(DealCloseService::class)->win($deal, $this->reasonOfKind(CloseReasonKind::Won), $rep);

        $this->assertFalse($rep->can('update', $deal));
        $this->assertFalse($rep->can('changeStage', $deal));
        $this->assertFalse($rep->can('close', $deal));
        $this->assertTrue($rep->can('reopen', $deal));

        $this->assertTrue($readOnly->can('view', $deal));
        $this->assertFalse($readOnly->can('reopen', $deal));
        $this->assertFalse($readOnly->can('close', $deal));

        $this->assertFalse(app(DealStageWorkflow::class)->canTransition($deal));

        try {
            app(DealStageWorkflow::class)->transition($deal, $this->openStage($deal, 1), $rep);
            $this->fail('A closed deal changed stage.');
        } catch (InvalidDealTransitionException $exception) {
            $this->assertSame(__('deals.validation.already_closed'), $exception->getMessage());
        }

        $this->expectException(InvalidDealTransitionException::class);
        app(DealCloseService::class)->lose($deal, $this->reasonOfKind(CloseReasonKind::Lost), $rep);
    }

    #[Test]
    public function reopening_returns_the_deal_to_the_default_stage_and_clears_the_close_columns(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $defaultStage = $deal->stage;
        $this->assertNotNull($defaultStage);
        $lostStage = $this->stageOfKind($deal, StageKind::Lost);

        app(DealCloseService::class)->lose($deal, $this->reasonOfKind(CloseReasonKind::Lost), $rep, 'Budget frozen');
        app(DealCloseService::class)->reopen($deal, $rep, 'Budget approved after all');

        $this->assertSame(DealStatus::Open, $deal->status);

        $deal->refresh();
        $this->assertSame(DealStatus::Open, $deal->status);
        $this->assertSame($defaultStage->getKey(), $deal->stage_id);
        $this->assertNull($deal->won_at);
        $this->assertNull($deal->lost_at);
        $this->assertNull($deal->close_reason_id);
        $this->assertNull($deal->lost_notes);

        $log = DealStageLog::query()->where('deal_id', $deal->getKey())->orderByDesc('id')->firstOrFail();
        $this->assertSame($lostStage->getKey(), (int) $log->from_stage_id);
        $this->assertSame($defaultStage->getKey(), (int) $log->to_stage_id);
        $this->assertSame('Budget approved after all', $log->notes);
        $this->assertSame(2, DealStageLog::query()->where('deal_id', $deal->getKey())->count());

        $audit = ActivityLog::query()->where('description', ActivityLogEvent::DealReopened->value)->latest('id')->firstOrFail();
        $this->assertSame($deal->getKey(), (int) $audit->subject_id);
        $this->assertSame($defaultStage->display_name, $audit->properties->get('to_stage'));
        $this->assertSame($lostStage->display_name, $audit->properties->get('from_stage'));

        $this->assertTrue($rep->can('update', $deal));
        $this->assertTrue($rep->can('close', $deal));
    }

    #[Test]
    public function reopening_an_open_deal_is_refused(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);

        try {
            app(DealCloseService::class)->reopen($deal, $rep);
            $this->fail('An open deal was reopened.');
        } catch (InvalidDealTransitionException $exception) {
            $this->assertSame(__('deals.validation.not_closed'), $exception->getMessage());
        }

        $this->assertSame(0, DealStageLog::query()->where('deal_id', $deal->getKey())->count());
    }

    #[Test]
    public function closing_is_refused_when_the_pipeline_has_no_closed_stage(): void
    {
        $rep = $this->salesRep();
        $pipeline = Pipeline::factory()->create();
        $only = PipelineStage::factory()->asDefault()->create(['pipeline_id' => $pipeline->getKey()]);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'pipeline_id' => $pipeline->getKey(), 'stage_id' => $only->getKey()]);

        try {
            app(DealCloseService::class)->win($deal, $this->reasonOfKind(CloseReasonKind::Won), $rep);
            $this->fail('A deal was won without a Won stage.');
        } catch (InvalidDealTransitionException $exception) {
            $this->assertSame(__('deals.validation.pipeline_has_no_closed_stage'), $exception->getMessage());
        }

        $this->assertSame(DealStatus::Open, $deal->refresh()->status);
    }

    private function stageOfKind(Deal $deal, StageKind $kind): PipelineStage
    {
        return PipelineStage::query()
            ->where('pipeline_id', $deal->pipeline_id)
            ->where('kind', $kind->value)
            ->orderBy('sort')
            ->firstOrFail();
    }

    /** The n-th Open stage of the deal's pipeline, in pipeline order (0 = the default stage). */
    private function openStage(Deal $deal, int $position): PipelineStage
    {
        return PipelineStage::query()
            ->where('pipeline_id', $deal->pipeline_id)
            ->where('kind', StageKind::Open->value)
            ->orderBy('sort')
            ->orderBy('id')
            ->skip($position)
            ->firstOrFail();
    }

    private function reasonOfKind(CloseReasonKind $kind): DealCloseReason
    {
        return DealCloseReason::query()->where('kind', $kind->value)->where('is_active', true)->orderBy('sort')->firstOrFail();
    }
}
